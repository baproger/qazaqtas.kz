<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Запись в журнале помощника: пара «вопрос — ответ» и список инструментов. */
class AiChatLog extends Model
{
    protected $table = 'ai_chat_log';

    public $timestamps = false;

    protected $fillable = ['user_id', 'question', 'answer', 'used_tools', 'created_at'];

    protected function casts(): array
    {
        return ['used_tools' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
