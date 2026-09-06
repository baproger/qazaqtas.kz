<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Реплика диалога: вопрос пользователя или ответ помощника.
 * Токены заполняются только у ответов — по ним считается расход.
 */
class AiMessage extends Model
{
    protected $fillable = ['conversation_id', 'role', 'content', 'input_tokens', 'output_tokens'];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }
}
