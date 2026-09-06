<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал ИИ-помощника: вопрос, ответ и какими инструментами он пользовался.
 * Заодно аудит — видно, кто и о чём спрашивал систему.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_chat_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('question');
            $table->text('answer');
            $table->json('used_tools')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_log');
    }
};
