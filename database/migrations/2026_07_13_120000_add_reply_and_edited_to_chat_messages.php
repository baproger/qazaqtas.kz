<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ответ-цитата (reply_to_id → на какое сообщение отвечают) и метка
     * редактирования (edited_at) — как в WhatsApp/Telegram.
     */
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('reply_to_id')->nullable()->after('user_id')
                ->constrained('chat_messages')->nullOnDelete();
            $table->timestamp('edited_at')->nullable()->after('attachments');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reply_to_id');
            $table->dropColumn('edited_at');
        });
    }
};
