<?php

namespace App\Http\Controllers;

use App\Models\ErrorLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Журнал ошибок — только админу. Здесь видно всё, что ломалось: от 404 до
 * падения базы, и по сайту, и по ERP.
 */
class ErrorLogController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasRole('admin'), 403);

        $level = $request->string('level')->toString();
        $status = $request->string('status')->toString() ?: 'open';
        $search = trim($request->string('search')->toString());

        $logs = ErrorLog::query()
            ->with(['user:id,name', 'resolver:id,name'])
            ->when(in_array($level, ErrorLog::LEVELS, true), fn ($q) => $q->where('level', $level))
            ->when($request->string('source')->toString(), fn ($q, $s) => $q->where('source', $s))
            ->when($status === 'open', fn ($q) => $q->whereNull('resolved_at'))
            ->when($status === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('message', 'like', "%{$search}%")
                ->orWhere('url', 'like', "%{$search}%")
                ->orWhere('kind', 'like', "%{$search}%")))
            ->orderByDesc('last_seen_at')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (ErrorLog $e) => [
                'id' => $e->id,
                'level' => $e->level,
                'source' => $e->source,
                'kind' => $e->kind,
                'status' => $e->status,
                'message' => $e->message,
                'url' => $e->url,
                'method' => $e->method,
                'file' => $e->file,
                'line' => $e->line,
                'trace' => $e->trace,
                'context' => $e->context,
                'user' => $e->user?->name,
                'ip' => $e->ip,
                'user_agent' => $e->user_agent,
                'count' => $e->count,
                'first_seen_at' => $e->first_seen_at?->toIso8601String(),
                'last_seen_at' => $e->last_seen_at?->toIso8601String(),
                'resolved_at' => $e->resolved_at?->toIso8601String(),
                'resolved_by' => $e->resolver?->name,
            ]);

        // Сводка за сутки: сколько незакрытых по каждому уровню.
        $since = now()->subDay();
        $stats = ErrorLog::open()->where('last_seen_at', '>=', $since)
            ->selectRaw('level, count(*) as n, sum(count) as hits')
            ->groupBy('level')->get()->keyBy('level');

        return Inertia::render('Errors/Index', [
            'logs' => $logs,
            'filters' => ['level' => $level, 'status' => $status, 'source' => $request->string('source')->toString(), 'search' => $search],
            'stats' => collect(ErrorLog::LEVELS)->mapWithKeys(fn ($l) => [$l => [
                'n' => (int) ($stats[$l]->n ?? 0),
                'hits' => (int) ($stats[$l]->hits ?? 0),
            ]]),
            'openTotal' => ErrorLog::open()->count(),
        ]);
    }

    /** «Разобрано» / «Открыть снова». */
    public function resolve(Request $request, ErrorLog $error): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);

        $error->update($error->resolved_at
            ? ['resolved_at' => null, 'resolved_by' => null]
            : ['resolved_at' => now(), 'resolved_by' => $request->user()->id]);

        return back();
    }

    /** Закрыть всё, что сейчас открыто (по фильтру уровня, если задан). */
    public function resolveAll(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);
        $data = $request->validate(['level' => ['nullable', Rule::in(ErrorLog::LEVELS)]]);

        ErrorLog::open()
            ->when($data['level'] ?? null, fn ($q, $l) => $q->where('level', $l))
            ->update(['resolved_at' => now(), 'resolved_by' => $request->user()->id]);

        return back()->with('success', 'Ошибки отмечены как разобранные.');
    }

    /** Удалить разобранные старше 30 дней — журнал не должен расти вечно. */
    public function purge(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);
        $n = ErrorLog::whereNotNull('resolved_at')->where('resolved_at', '<', now()->subDays(30))->delete();

        return back()->with('success', "Удалено разобранных: {$n}.");
    }

    /**
     * Ошибка из браузера. Открыт всем (витрина без входа), но с лимитом:
     * журнал — не место для DoS.
     */
    public function browser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'kind' => ['nullable', 'string', 'max:100'],
            'file' => ['nullable', 'string', 'max:500'],
            'line' => ['nullable', 'integer', 'min:0'],
            'stack' => ['nullable', 'string', 'max:8000'],
            'url' => ['nullable', 'string', 'max:2000'],
        ]);

        ErrorLog::fromBrowser($data, $request);

        return response()->json(['ok' => true]);
    }
}
