<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Диалог с ИИ-помощником. Приватен: принадлежит одному пользователю,
 * доступ проверяется в контроллере сравнением user_id.
 */
class AiConversation extends Model
{
    protected $fillable = ['user_id', 'title'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class, 'conversation_id');
    }

    /** Заголовок диалога из первого вопроса — списку нужна короткая строка. */
    public static function titleFrom(string $question): string
    {
        $t = trim(preg_replace('/\s+/u', ' ', $question));

        return mb_strimwidth($t, 0, 120, '…');
    }
}
