<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Запись журнала ошибок (§ «Журнал ошибок»).
 *
 * Уровни:
 *   info     — 404: кто-то открыл несуществующий адрес;
 *   warning  — 403 / 419 / 429 / ошибки в браузере: работе мешает, но не ломает;
 *   error    — исключение PHP, 500;
 *   critical — база данных недоступна / запрос упал.
 */
class ErrorLog extends Model
{
    public const LEVELS = ['info', 'warning', 'error', 'critical'];

    protected $fillable = [
        'level', 'source', 'kind', 'status', 'fingerprint', 'message', 'url', 'method',
        'file', 'line', 'trace', 'context', 'user_id', 'ip', 'user_agent', 'count',
        'first_seen_at', 'last_seen_at', 'resolved_at', 'resolved_by',
    ];

    protected $casts = [
        'context' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereNull('resolved_at');
    }

    /**
     * Записать исключение. Никогда не бросает: журнал не должен ронять
     * страницу второй раз — если база лежит, ошибка уйдёт только в лог-файл.
     */
    public static function fromThrowable(Throwable $e, ?Request $request = null): ?self
    {
        if ($e instanceof ValidationException) {
            return null; // ошибка ввода — не сбой
        }

        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
        if ($e instanceof HttpExceptionInterface && ! in_array($status, [403, 404, 405, 419, 429, 503], true)) {
            return null;
        }

        $level = match (true) {
            $e instanceof QueryException, $e instanceof PDOException => 'critical',
            $status >= 500 => 'error',
            $status === 404 => 'info',
            default => 'warning',
        };
        $kind = $e instanceof HttpExceptionInterface ? 'HTTP '.$status : class_basename($e);
        $message = $e->getMessage() !== '' ? $e->getMessage() : $kind;
        $file = $e instanceof HttpExceptionInterface ? null : self::shortPath($e->getFile());
        $line = $e instanceof HttpExceptionInterface ? null : $e->getLine();

        return self::record([
            'level' => $level,
            'source' => 'server',
            'kind' => $kind,
            'status' => $status,
            'message' => mb_substr($message, 0, 2000),
            'file' => $file,
            'line' => $line,
            'trace' => $e instanceof HttpExceptionInterface ? null : self::shortTrace($e),
            // Отпечаток: у HTTP-ошибок — адрес (404 на разных страницах — разные
            // проблемы), у исключений — где упало.
            'fingerprint' => hash('sha256', $e instanceof HttpExceptionInterface
                ? $kind.'|'.($request?->path() ?? '')
                : get_class($e).'|'.$e->getFile().'|'.$e->getLine().'|'.mb_substr($message, 0, 200)),
        ], $request);
    }

    /**
     * Ошибка из браузера (window.onerror / unhandledrejection).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromBrowser(array $data, Request $request): ?self
    {
        $message = mb_substr((string) ($data['message'] ?? 'Ошибка в браузере'), 0, 2000);
        $file = mb_substr((string) ($data['file'] ?? ''), 0, 500) ?: null;
        $fingerprint = hash('sha256', 'browser|'.$message.'|'.$file.'|'.($data['line'] ?? ''));

        // Потолок незнакомых браузерных ошибок: повторы и так схлопываются по
        // отпечатку (count++), а распределённый флуд УНИКАЛЬНЫМИ сообщениями
        // не должен раздувать таблицу на шаред-хостинге. Эндпоинт публичный.
        if (! self::open()->where('fingerprint', $fingerprint)->exists()
            && self::where('source', 'browser')->count() >= 2000) {
            return null;
        }

        return self::record([
            'level' => 'warning',
            'source' => 'browser',
            'kind' => 'JS '.class_basename((string) ($data['kind'] ?? 'Error')),
            'status' => null,
            'message' => $message,
            'file' => $file,
            'line' => isset($data['line']) ? (int) $data['line'] : null,
            'trace' => isset($data['stack']) ? mb_substr((string) $data['stack'], 0, 8000) : null,
            'fingerprint' => $fingerprint,
            'url' => mb_substr((string) ($data['url'] ?? $request->headers->get('referer', '')), 0, 2000) ?: null,
        ], $request);
    }

    /** @param  array<string, mixed>  $attributes */
    private static function record(array $attributes, ?Request $request): ?self
    {
        try {
            $attributes += [
                'url' => $request ? mb_substr($request->fullUrl(), 0, 2000) : null,
                'method' => $request?->method(),
                'user_id' => $request?->user()?->id,
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
                'context' => $request ? ['route' => $request->route()?->getName(), 'referer' => $request->headers->get('referer')] : null,
            ];

            $open = self::open()->where('fingerprint', $attributes['fingerprint'])->first();
            if ($open) {
                $open->fill([
                    'count' => $open->count + 1,
                    'last_seen_at' => now(),
                    'url' => $attributes['url'] ?? $open->url,
                    'user_id' => $attributes['user_id'] ?? $open->user_id,
                    'ip' => $attributes['ip'] ?? $open->ip,
                ])->save();

                return $open;
            }

            return self::create($attributes + ['first_seen_at' => now(), 'last_seen_at' => now()]);
        } catch (Throwable) {
            return null;
        }
    }

    private static function shortPath(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), '/\\');
    }

    /** Первые кадры трассировки без vendor — то, что читает человек. */
    private static function shortTrace(Throwable $e): string
    {
        $lines = [];
        foreach ($e->getTrace() as $i => $frame) {
            if ($i >= 25) {
                break;
            }
            $file = isset($frame['file']) ? self::shortPath($frame['file']).':'.($frame['line'] ?? '?') : '[internal]';
            $call = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');
            $lines[] = "#{$i} {$file} {$call}()";
        }

        return mb_substr(implode("\n", $lines), 0, 8000);
    }
}
