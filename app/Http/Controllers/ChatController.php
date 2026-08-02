<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ChatController extends Controller
{
    /**
     * Ensure a single global chat exists and the user participates in it.
     */
    private function ensureGlobalChat(User $user): Chat
    {
        $global = Chat::firstOrCreate(['type' => 'global'], ['name' => 'Общий чат', 'is_active' => true]);
        $global->participants()->syncWithoutDetaching([$user->id => ['joined_at' => now()]]);

        return $global;
    }

    /**
     * Чаты, доступные пользователю: общий + те, где он участник. Группы с
     * company_id видят только сотрудники этой фирмы (админ — все), чтобы
     * группы BAIA и ASU не путались между собой.
     */
    private function visibleChats(User $user): \Illuminate\Database\Eloquent\Builder
    {
        return Chat::query()
            ->where(fn ($q) => $q->where('type', 'global')
                ->orWhereHas('participants', fn ($p) => $p->where('users.id', $user->id)))
            ->when(! $user->hasRole('admin'), fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('company_id')
                ->orWhereIn('company_id', $user->companies()->pluck('companies.id'))));
    }

    public function index(Request $request): Response
    {
        $user = $request->user();
        $this->ensureGlobalChat($user);

        $chats = $this->visibleChats($user)
            ->with(['participants:id,name,avatar', 'company:id,name', 'lastMessage.user:id,name', 'pinnedMessage.user:id,name'])
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->get();

        // Непрочитанные на пользователя: сообщения новее last_read_message_id и
        // не свои. Один запрос с коррелированным подзапросом (без N+1).
        $unread = \App\Models\ChatMessage::query()
            ->where('user_id', '!=', $user->id)
            ->whereIn('chat_id', $chats->pluck('id'))
            ->whereRaw('id > COALESCE((select last_read_message_id from chat_reads where chat_reads.chat_id = chat_messages.chat_id and chat_reads.user_id = ?), 0)', [$user->id])
            ->selectRaw('chat_id, count(*) c')->groupBy('chat_id')->pluck('c', 'chat_id');

        $chats = $chats
            ->map(function ($c) use ($user, $unread) {
                // For a personal chat with no explicit name, show the other participant.
                $name = $c->name;
                if (! $name && $c->type === 'personal') {
                    $name = $c->participants->firstWhere('id', '!=', $user->id)?->name;
                }
                $name = $name ?: ($c->type === 'global' ? 'Общий чат' : 'Чат #'.$c->id);
                $last = $c->lastMessage;

                // Chat avatar: the group photo, or (for personal chats) the other person's photo.
                $avatar = $c->avatar ? route('chat.avatar', $c->id).'?v='.$c->updated_at->timestamp : null;
                if (! $avatar && $c->type === 'personal') {
                    $avatar = $c->participants->firstWhere('id', '!=', $user->id)?->avatar;
                }

                return [
                    'id' => $c->id,
                    'type' => $c->type,
                    'name' => $name,
                    'company_id' => $c->company_id,
                    'company_name' => $c->company?->name,
                    'description' => $c->description,
                    'avatar' => $avatar,
                    'deal_id' => $c->deal_id,
                    'messages_count' => $c->messages_count,
                    'unread' => (int) ($unread[$c->id] ?? 0),
                    // Закреплённое сообщение (баннер сверху чата).
                    'pinned' => $c->pinnedMessage ? [
                        'id' => $c->pinnedMessage->id,
                        'author' => $c->pinnedMessage->user?->name,
                        'message' => \Illuminate\Support\Str::limit((string) $c->pinnedMessage->message, 90) ?: '📎 вложение',
                    ] : null,
                    'participants' => $c->participants->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'avatar' => $p->avatar])->values(),
                    'last' => $last ? [
                        'id' => $last->id,
                        'text' => \Illuminate\Support\Str::limit((string) $last->message, 42),
                        'author' => $last->user?->name,
                        'author_id' => $last->user_id,
                        'time' => $last->created_at->toIso8601String(),
                    ] : null,
                ];
            });

        $canManage = $user->hasAnyRole(['admin', 'director']);

        return Inertia::render('Chat/Index', [
            'chats' => $chats,
            'users' => User::where('is_active', true)->where('id', '!=', $user->id)->orderBy('name')
                ->with('companies:companies.id')->get(['id', 'name', 'avatar'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'avatar' => $u->avatar, 'company_ids' => $u->companies->pluck('id')]),
            'companies' => \App\Models\Company::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canCreateGroup' => $canManage,
            // Корзина: удалённые чаты может вернуть или стереть навсегда админ/директор.
            'trashedChats' => $canManage
                ? Chat::onlyTrashed()->orderByDesc('deleted_at')->get(['id', 'name', 'type', 'deleted_at'])
                    ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name ?: 'Чат #'.$c->id, 'type' => $c->type, 'deleted_at' => $c->deleted_at->toIso8601String()])
                : [],
        ]);
    }

    /**
     * Лёгкий поллинг для списка чатов: непрочитанные и последнее сообщение —
     * бейджи и звук новых сообщений работают без перезагрузки страницы.
     */
    public function state(Request $request): JsonResponse
    {
        $user = $request->user();
        $chats = $this->visibleChats($user)->with('lastMessage.user:id,name')->get(['id', 'updated_at']);

        $unread = ChatMessage::query()
            ->where('user_id', '!=', $user->id)
            ->whereIn('chat_id', $chats->pluck('id'))
            ->whereRaw('id > COALESCE((select last_read_message_id from chat_reads where chat_reads.chat_id = chat_messages.chat_id and chat_reads.user_id = ?), 0)', [$user->id])
            ->selectRaw('chat_id, count(*) c')->groupBy('chat_id')->pluck('c', 'chat_id');

        return response()->json(['state' => $chats->mapWithKeys(fn ($c) => [$c->id => [
            'unread' => (int) ($unread[$c->id] ?? 0),
            'last' => $c->lastMessage ? [
                'id' => $c->lastMessage->id,
                'text' => Str::limit((string) $c->lastMessage->message, 42) ?: '📎 вложение',
                'author' => $c->lastMessage->user?->name,
                'author_id' => $c->lastMessage->user_id,
                'time' => $c->lastMessage->created_at->toIso8601String(),
            ] : null,
        ]])]);
    }

    public function messages(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeParticipant($request, $chat);

        $uid = $request->user()->id;
        $messages = $chat->messages()
            ->with(['user:id,name,avatar', 'replyTo:id,user_id,message', 'replyTo.user:id,name', 'reactions:id,chat_message_id,user_id,emoji'])
            ->when($request->integer('after'), fn ($q, $after) => $q->where('id', '>', $after))
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                // Автор мог быть удалён из БД — чат не должен падать (prod-лог 15.07).
                'user_name' => $m->user?->name ?? 'Удалённый пользователь',
                'user_avatar' => $m->user?->avatar,
                'message' => $m->message,
                'attachments' => collect($m->attachments ?? [])->values()->map(fn ($a, $i) => [
                    'name' => $a['name'] ?? 'файл',
                    'size' => (int) ($a['size'] ?? 0),
                    'mime' => $a['mime'] ?? '',
                    'is_image' => Str::startsWith($a['mime'] ?? '', 'image/'),
                    'url' => route('chat.attachment', [$m->id, $i]),
                ]),
                // Цитата: на какое сообщение отвечают (автор + короткий текст).
                'reply_to' => $m->replyTo ? [
                    'id' => $m->replyTo->id,
                    'user_name' => $m->replyTo->user?->name,
                    'message' => Str::limit((string) $m->replyTo->message, 120),
                ] : null,
                'edited' => $m->edited_at !== null,
                // Реакции, сгруппированные по эмодзи: [{emoji, count, mine}].
                'reactions' => $m->reactions->groupBy('emoji')->map(fn ($g, $emoji) => [
                    'emoji' => $emoji,
                    'count' => $g->count(),
                    'mine' => $g->contains('user_id', $uid),
                ])->values(),
                'can_delete' => $this->canDeleteMessage($request->user(), $m),
                'can_edit' => $m->user_id === $request->user()->id,
                'created_at' => $m->created_at->toIso8601String(),
            ]);

        // «Кто прочитал»: user_id → id последнего прочитанного сообщения.
        // Фронт по нему рисует ✓✓ и список имён на своих сообщениях.
        $reads = \Illuminate\Support\Facades\DB::table('chat_reads')
            ->where('chat_id', $chat->id)->pluck('last_read_message_id', 'user_id');

        return response()->json(['messages' => $messages, 'reads' => $reads]);
    }

    public function sendMessage(Request $request, Chat $chat): RedirectResponse
    {
        $this->authorizeParticipant($request, $chat);

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:5000'],
            'reply_to_id' => ['nullable', 'integer', 'exists:chat_messages,id'],
            'mention_ids' => ['nullable', 'array'],
            'mention_ids.*' => ['integer'],
            'file' => ['nullable', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,png,jpg,jpeg,gif,webp,zip,rar,txt,csv'],
        ]);

        if (blank($validated['message'] ?? null) && ! $request->hasFile('file')) {
            throw ValidationException::withMessages(['message' => 'Введите сообщение или прикрепите файл.']);
        }

        // Отвечать можно только на сообщение ЭТОГО чата.
        $replyToId = null;
        if (! empty($validated['reply_to_id'])) {
            $replyToId = ChatMessage::where('id', $validated['reply_to_id'])->where('chat_id', $chat->id)->value('id');
        }

        $attachments = [];
        if ($file = $request->file('file')) {
            $attachments[] = [
                'path' => $file->store('chat', 'local'),
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
            ];
        }

        $msg = $chat->messages()->create([
            'user_id' => $request->user()->id,
            'reply_to_id' => $replyToId,
            'message' => $validated['message'] ?? '',
            'attachments' => $attachments ?: null,
        ]);
        $chat->touch();

        // Упоминания @имя: уведомляем упомянутых участников (кроме себя).
        $mentionIds = collect($validated['mention_ids'] ?? [])->map(fn ($v) => (int) $v)
            ->reject(fn ($id) => $id === $request->user()->id)->unique();
        if ($mentionIds->isNotEmpty()) {
            $participantIds = $chat->type === 'global'
                ? User::where('is_active', true)->pluck('id')
                : $chat->participants()->pluck('users.id');
            User::whereIn('id', $mentionIds->intersect($participantIds))->get()
                ->each(fn ($u) => $u->notify(new \App\Notifications\ChatMention($chat, $request->user(), $msg)));
        }

        // Web Push всем участникам, кроме автора — приходит как WhatsApp даже при
        // свёрнутом браузере/закрытой вкладке. Шлём ПОСЛЕ ответа (terminating),
        // чтобы не задерживать отправку сообщения.
        $recipientIds = ($chat->type === 'global'
            ? User::where('is_active', true)->pluck('id')
            : $chat->participants()->pluck('users.id'))
            ->reject(fn ($id) => (int) $id === $request->user()->id)->values()->all();
        if ($recipientIds !== []) {
            $author = $request->user()->name;
            $chatName = $chat->name ?: 'Чат';
            $text = Str::limit((string) $msg->message, 80) ?: '📎 вложение';
            app()->terminating(fn () => app(\App\Services\PushService::class)
                ->sendToUsers($recipientIds, '💬 '.$chatName, $author.': '.$text, route('chat.index', absolute: false)));
        }

        return back();
    }

    /** Отметить чат прочитанным до последнего сообщения (серверный учёт). */
    public function markRead(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeParticipant($request, $chat);
        $lastId = (int) $chat->messages()->max('id');
        \Illuminate\Support\Facades\DB::table('chat_reads')->updateOrInsert(
            ['chat_id' => $chat->id, 'user_id' => $request->user()->id],
            ['last_read_message_id' => $lastId, 'updated_at' => now(), 'created_at' => now()]
        );

        return response()->json(['ok' => true]);
    }

    /** Редактирование сообщения — ТОЛЬКО автор своего. Ставит метку «изменено». */
    public function updateMessage(Request $request, ChatMessage $message): JsonResponse
    {
        $this->authorizeParticipant($request, $message->chat);
        abort_unless($message->user_id === $request->user()->id, 403, 'Редактировать можно только свои сообщения.');

        $data = $request->validate(['message' => ['required', 'string', 'max:5000']]);
        $message->update(['message' => $data['message'], 'edited_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Поставить/снять реакцию-эмодзи (тумблер) — любой участник чата. */
    public function react(Request $request, ChatMessage $message): JsonResponse
    {
        $this->authorizeParticipant($request, $message->chat);
        $data = $request->validate(['emoji' => ['required', 'string', 'max:16']]);

        $existing = $message->reactions()
            ->where('user_id', $request->user()->id)->where('emoji', $data['emoji'])->first();
        if ($existing) {
            $existing->delete();
        } else {
            $message->reactions()->create(['user_id' => $request->user()->id, 'emoji' => $data['emoji']]);
        }

        return response()->json(['ok' => true]);
    }

    /** Закрепить/открепить сообщение чата (тумблер) — админ/директор. */
    public function pinMessage(Request $request, ChatMessage $message): JsonResponse
    {
        $chat = $message->chat;
        $this->authorizeParticipant($request, $chat);
        abort_unless($request->user()->hasAnyRole(['admin', 'director']), 403, 'Закрепляет админ или директор.');

        $chat->update([
            'pinned_message_id' => $chat->pinned_message_id === $message->id ? null : $message->id,
        ]);

        return response()->json(['ok' => true]);
    }

    /** Удаление сообщения: любое — ТОЛЬКО админ; автор — своё. */
    public function destroyMessage(Request $request, ChatMessage $message): JsonResponse
    {
        $this->authorizeParticipant($request, $message->chat);
        abort_unless($this->canDeleteMessage($request->user(), $message), 403);

        foreach (($message->attachments ?? []) as $a) {
            if (! empty($a['path'])) {
                Storage::disk('local')->delete($a['path']);
            }
        }
        $message->delete();

        return response()->json(['ok' => true]);
    }

    public function downloadAttachment(Request $request, ChatMessage $message, int $index)
    {
        $this->authorizeParticipant($request, $message->chat);
        $a = ($message->attachments ?? [])[$index] ?? abort(404);
        abort_unless(! empty($a['path']) && Storage::disk('local')->exists($a['path']), 404);

        return Storage::disk('local')->response($a['path'], $a['name'] ?? 'file');
    }

    /** All files ever sent in a chat — for the «Вложения» tab. */
    public function attachments(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeParticipant($request, $chat);

        $files = [];
        $chat->messages()->whereNotNull('attachments')->with('user:id,name')->orderByDesc('id')->get()
            ->each(function ($m) use (&$files) {
                foreach (($m->attachments ?? []) as $i => $a) {
                    $files[] = [
                        'name' => $a['name'] ?? 'файл',
                        'size' => (int) ($a['size'] ?? 0),
                        'mime' => $a['mime'] ?? '',
                        'is_image' => Str::startsWith($a['mime'] ?? '', 'image/'),
                        'url' => route('chat.attachment', [$m->id, $i]),
                        'author' => $m->user?->name,
                        'time' => $m->created_at->toIso8601String(),
                    ];
                }
            });

        return response()->json(['attachments' => $files]);
    }

    public function avatar(Request $request, Chat $chat)
    {
        $this->authorizeParticipant($request, $chat);
        abort_unless($chat->avatar && Storage::disk('local')->exists($chat->avatar), 404);

        return Storage::disk('local')->response($chat->avatar);
    }

    private function canDeleteMessage(User $user, ChatMessage $message): bool
    {
        // Любое чужое сообщение удаляет ТОЛЬКО админ; своё — автор.
        return $user->hasRole('admin') || $message->user_id === $user->id;
    }

    public function store(Request $request): RedirectResponse
    {
        if ($request->input('type') === 'group') {
            abort_unless($request->user()->hasAnyRole(['admin', 'director']), 403, 'Группы создаёт только администратор или директор.');
        }
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', 'in:personal,group'],
            'company_id' => ['nullable', 'exists:companies,id'],
            'participants' => ['array'],
            'participants.*' => ['exists:users,id'],
        ]);

        $chat = Chat::create([
            'type' => $validated['type'],
            // Компания — только у групп: BAIA/ASU видят каждая своё, null — обе.
            'company_id' => $validated['type'] === 'group' ? ($validated['company_id'] ?? null) : null,
            'name' => $validated['name'] ?? null,
            'description' => $validated['description'] ?? null,
            'is_active' => true,
        ]);

        $ids = collect($validated['participants'] ?? [])->push($request->user()->id)->unique();
        $chat->participants()->syncWithoutDetaching(
            $ids->mapWithKeys(fn ($id) => [$id => ['joined_at' => now()]])->all()
        );

        return back()->with('success', 'Чат создан.');
    }

    /** Rename a group and re-sync its participants (admin/director only). */
    public function update(Request $request, Chat $chat): RedirectResponse
    {
        abort_if($chat->type === 'global', 403, 'Общий чат нельзя изменить.');
        abort_unless($request->user()->hasAnyRole(['admin', 'director']), 403);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'company_id' => ['nullable', 'exists:companies,id'],
            'participants' => ['array'],
            'participants.*' => ['exists:users,id'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);

        $data = [
            'name' => $validated['name'] ?? $chat->name,
            'description' => $validated['description'] ?? $chat->description,
        ];
        if ($chat->type === 'group' && $request->has('company_id')) {
            $data['company_id'] = $validated['company_id'] ?? null;
        }
        if ($photo = $request->file('photo')) {
            if ($chat->avatar) {
                Storage::disk('local')->delete($chat->avatar);
            }
            $data['avatar'] = $photo->store('chat-avatars', 'local');
        }
        $chat->update($data);

        if ($request->has('participants')) {
            $ids = collect($validated['participants'])->push($request->user()->id)->unique();
            $chat->participants()->sync($ids->mapWithKeys(fn ($id) => [$id => ['joined_at' => now()]])->all());
        }

        return back()->with('success', 'Чат обновлён.');
    }

    /**
     * Чат — в корзину (admin/director; общий чат защищён). Сообщения и
     * участники сохраняются: из корзины чат можно вернуть как был.
     */
    public function destroy(Request $request, Chat $chat): RedirectResponse
    {
        abort_if($chat->type === 'global', 403, 'Общий чат нельзя удалить.');
        // Канал сделки живёт вместе со сделкой — удаляется только с ней.
        abort_if($chat->deal_id !== null, 403, 'Чат сделки удаляется вместе со сделкой.');
        abort_unless($request->user()->hasAnyRole(['admin', 'director']), 403);

        $chat->delete();

        return back()->with('success', 'Чат перемещён в корзину.');
    }

    /** Вернуть чат из корзины (admin/director). */
    public function restore(Request $request, int $id): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'director']), 403);
        Chat::onlyTrashed()->findOrFail($id)->restore();

        return back()->with('success', 'Чат восстановлен.');
    }

    /** Стереть чат из корзины НАВСЕГДА: сообщения, файлы, участники (admin/director). */
    public function forceDestroy(Request $request, int $id): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'director']), 403);
        $chat = Chat::onlyTrashed()->findOrFail($id);

        $chat->messages()->whereNotNull('attachments')->get()->each(function ($m) {
            foreach (($m->attachments ?? []) as $a) {
                if (! empty($a['path'])) {
                    Storage::disk('local')->delete($a['path']);
                }
            }
        });
        if ($chat->avatar) {
            Storage::disk('local')->delete($chat->avatar);
        }
        $chat->messages()->delete();
        $chat->participants()->detach();
        $chat->forceDelete();

        return back()->with('success', 'Чат удалён навсегда.');
    }

    /** Добавить участника в группу — новый сотрудник присоединяется к чату. */
    public function addMember(Request $request, Chat $chat): RedirectResponse
    {
        abort_unless($chat->type === 'group', 403, 'Участники добавляются только в группы.');
        abort_unless($request->user()->hasAnyRole(['admin', 'director']), 403);
        $data = $request->validate(['user_id' => ['required', 'exists:users,id']]);

        $chat->participants()->syncWithoutDetaching([(int) $data['user_id'] => ['joined_at' => now()]]);

        return back()->with('success', 'Участник добавлен.');
    }

    /** Убрать участника из группы (admin/director). */
    public function removeMember(Request $request, Chat $chat, User $user): RedirectResponse
    {
        abort_unless($chat->type === 'group', 403);
        abort_unless($request->user()->hasAnyRole(['admin', 'director']), 403);

        $chat->participants()->detach($user->id);

        return back()->with('success', 'Участник удалён из группы.');
    }

    private function authorizeParticipant(Request $request, Chat $chat): void
    {
        if ($chat->type === 'global') {
            return;
        }
        if ($chat->deal_id) {
            $deal = \App\Models\Deal::find($chat->deal_id);
            abort_unless($deal && $request->user()->can('view', $deal), 403);
            return;
        }
        // Группа фирмы: сотрудник другой фирмы не попадает даже по прямой ссылке.
        abort_unless($request->user()->worksInCompany($chat->company_id), 403);
        abort_unless($chat->participants()->where('users.id', $request->user()->id)->exists(), 403);
    }
}
