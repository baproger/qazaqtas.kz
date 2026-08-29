<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал ошибок: что ломалось на сайте и в ERP — от 404 до падения БД.
 *
 * Один и тот же сбой не плодит строк: у ошибки есть отпечаток
 * (класс + сообщение + файл + строка / адрес), и повтор увеличивает `count`
 * у незакрытой записи. Закрыл админ («Разобрано») — следующий повтор заведёт
 * новую строку: значит, не починили.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 10)->index();          // info | warning | error | critical
            $table->string('source', 10)->default('server'); // server | browser
            $table->string('kind')->nullable();             // класс исключения или "HTTP 404"
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('fingerprint', 64)->index();
            $table->text('message');
            $table->string('url', 2048)->nullable();
            $table->string('method', 10)->nullable();
            $table->string('file', 512)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->text('trace')->nullable();
            $table->json('context')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_logs');
    }
};
