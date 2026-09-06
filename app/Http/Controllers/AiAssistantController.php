<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\AiAssistantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * ИИ-помощник руководителя. Доступ — только роли admin и director
 * (Gate `use-ai-assistant`, навешан на маршруты).
 *
 * Диалоги приватные: каждый видит только свои, даже админ — чужой диалог
 * это переписка, а не рабочий документ.
 */
class AiAssistantController extends Controller
{
    public function index(Request $request)
    {
        return $this->render($request, null);
    }

    public function show(Request $request, AiConversation $conversation)
    {
        $this->authorizeOwn($request, $conversation);

        return $this->render($request, $conversation);
    }

    public function send(Request $request, AiAssistantService $ai)
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:4000'],
            'conversation_id' => ['nullable', 'integer', 'exists:ai_conversations,id'],
        ]);

        $this->checkDailyLimit($request);

        // Ключа может не быть вовсе: nullable-поле, которого нет во входе,
        // validate() в результат не кладёт.
        $conversation = ($data['conversation_id'] ?? null)
            ? AiConversation::findOrFail($data['conversation_id'])
            : null;

        if ($conversation) {
            $this->authorizeOwn($request, $conversation);
        } else {
            $conversation = AiConversation::create([
                'user_id' => $request->user()->id,
                'title' => AiConversation::titleFrom($data['message']),
            ]);
        }

        // Вопрос сохраняем до обращения к модели: даже если ИИ не ответит,
        // вопрос останется в диалоге и в аудите.
        $conversation->messages()->create(['role' => 'user', 'content' => $data['message']]);

        $answer = $ai->answer($conversation, $data['message']);

        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $answer['content'],
            'input_tokens' => $answer['input_tokens'],
            'output_tokens' => $answer['output_tokens'],
        ]);

        $conversation->touch(); // свежий диалог — наверх списка

        return redirect()->route('ai.show', $conversation);
    }

    public function destroy(Request $request, AiConversation $conversation)
    {
        $this->authorizeOwn($request, $conversation);
        $conversation->delete();

        return redirect()->route('ai.index')->with('success', __('Диалог удалён'));
    }

    private function render(Request $request, ?AiConversation $conversation)
    {
        $user = $request->user();

        return Inertia::render('Ai/Index', [
            'conversations' => AiConversation::where('user_id', $user->id)
                ->latest('updated_at')
                ->limit(50)
                ->get(['id', 'title', 'updated_at']),
            'conversation' => $conversation ? [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'messages' => $conversation->messages()
                    ->orderBy('id')
                    ->get(['id', 'role', 'content', 'created_at']),
            ] : null,
            'configured' => (bool) config('services.anthropic.key'),
            'usedToday' => $this->usedToday($request),
            'dailyLimit' => (int) config('services.anthropic.daily_limit'),
        ]);
    }

    /** Диалог приватен: чужой не открыть и не удалить. */
    private function authorizeOwn(Request $request, AiConversation $conversation): void
    {
        abort_unless($conversation->user_id === $request->user()->id, 403);
    }

    /** Сколько вопросов пользователь задал сегодня. */
    private function usedToday(Request $request): int
    {
        return AiMessage::where('role', 'user')
            ->whereDate('ai_messages.created_at', today())
            ->whereIn('conversation_id', DB::table('ai_conversations')
                ->where('user_id', $request->user()->id)
                ->select('id'))
            ->count();
    }

    private function checkDailyLimit(Request $request): void
    {
        $limit = (int) config('services.anthropic.daily_limit');

        if ($limit > 0 && $this->usedToday($request) >= $limit) {
            throw ValidationException::withMessages([
                'message' => __('Дневной лимит вопросов исчерпан (:limit). Помощник снова будет доступен завтра.', ['limit' => $limit]),
            ]);
        }
    }
}
