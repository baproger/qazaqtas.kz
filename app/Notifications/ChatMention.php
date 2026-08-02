<?php

namespace App\Notifications;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Пользователя упомянули (@имя) в чате. */
class ChatMention extends Notification
{
    use Queueable;

    public function __construct(public Chat $chat, public User $from, public ChatMessage $msg) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $name = $this->chat->name ?: ($this->chat->type === 'global' ? 'Общий чат' : 'Чат');

        return [
            'type' => 'chat_mention',
            'title' => 'Вас упомянули в чате',
            'message' => $this->from->name.' в «'.$name.'»: '.\Illuminate\Support\Str::limit((string) $this->msg->message, 80),
            'deal_number' => $name,
            'url' => route('chat.index', [], false),
        ];
    }
}
