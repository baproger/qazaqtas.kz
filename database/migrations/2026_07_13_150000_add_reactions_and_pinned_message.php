<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Этап 3: реакции-эмодзи на сообщения + закреплённое сообщение чата.
     */
    public function up(): void
    {
        Schema::create('chat_message_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();
            // Один и тот же эмодзи от одного пользователя — только раз.
            $table->unique(['chat_message_id', 'user_id', 'emoji']);
        });

        Schema::table('chats', function (Blueprint $table) {
            $table->foreignId('pinned_message_id')->nullable()->after('avatar')
                ->constrained('chat_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pinned_message_id');
        });
        Schema::dropIfExists('chat_message_reactions');
    }
};
