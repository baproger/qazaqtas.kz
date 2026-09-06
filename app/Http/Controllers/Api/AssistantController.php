<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiChatLog;
use App\Services\Ai\AssistantAgent;
use App\Services\Ai\AssistantException;
use App\Services\LocalAnswerService;
use App\Support\AiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * API ИИ-помощника. Доступ закрыт Gate `use-ai-assistant` (admin и director):
 * помощник видит деньги, склад и загрузку людей по всей компании.
 */
class AssistantController extends Controller
{
    /** Восемь вопросов в минуту на человека — защита и от спама, и от квот. */
    private const PER_MINUTE = 8;

    public function ask(Request $request, AssistantAgent $agent): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:2', 'max:2000'],
            'history' => ['sometimes', 'array', 'max:10'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:4000'],
        ]);

        // Лимит бережёт ВНЕШНИЙ сервис и его квоты. Ответы бесплатного
        // режима — обычный запрос к своей базе, ограничивать их незачем:
        // раньше пара нажатий на примеры съедала лимит и человек упирался
        // в 429, ничего толком не спросив.
        $limited = AiKey::isSet();
        $key = 'assistant:'.$request->user()->id;

        if ($limited && RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            return response()->json([
                'error' => 'Слишком много вопросов подряд — подождите '
                    .RateLimiter::availableIn($key).' сек. и повторите.',
            ], 429);
        }

        if ($limited) {
            RateLimiter::hit($key, 60);
        }

        try {
            $result = $agent->ask($request->user(), $data['question'], $data['history'] ?? []);
        } catch (AssistantException $e) {
            // Ключа нет или сервис недоступен — типовой вопрос всё равно
            // обслужим бесплатным режимом, он читает ту же базу.
            $local = app(LocalAnswerService::class)->answer($data['question']);

            if ($local !== null) {
                $result = ['answer' => $local, 'used_tools' => ['local']];
                $this->log($request, $data['question'], $result);

                return response()->json($result + ['notice' => $e->getMessage()]);
            }

            // Текст уже человеческий — отдаём как есть.
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Помощник не смог ответить. Попробуйте ещё раз.'], 500);
        }

        $this->log($request, $data['question'], $result);

        return response()->json([
            'answer' => $result['answer'],
            'used_tools' => $result['used_tools'],
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        return response()->json([
            'items' => AiChatLog::with('user:id,name')
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn ($r) => [
                    'id' => $r->id,
                    'question' => $r->question,
                    'answer' => $r->answer,
                    'used_tools' => $r->used_tools ?? [],
                    'user' => $r->user?->name,
                    'created_at' => $r->created_at?->toIso8601String(),
                ]),
        ]);
    }

    /** Журнал — дело второе: его сбой не должен отнимать у человека ответ. */
    private function log(Request $request, string $question, array $result): void
    {
        try {
            AiChatLog::create([
                'user_id' => $request->user()->id,
                'question' => $question,
                'answer' => $result['answer'],
                'used_tools' => $result['used_tools'],
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
