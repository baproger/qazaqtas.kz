<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Структура компании и область доступа — одна миграция, потому что это одна
 * мысль: дерево отделов существует ради того, чтобы у права появилась граница.
 *
 * До сих пор отделы были плоским списком, а «сколько человек видит» решал код:
 * руководство — все сделки, остальные — свои. Между этими двумя ответами нет
 * места для «руководитель отдела продаж видит сделки своего отдела», а именно
 * его и спрашивают чаще всего.
 *
 * Теперь у права есть ОБЛАСТЬ (`scope`), и дерево отвечает, что она значит:
 *
 *   none            — не пускать вовсе
 *   own             — только свои записи
 *   department      — свой отдел
 *   department_tree — свой отдел и все подчинённые отделы
 *   all             — вся компания
 *
 * `role_module_access` хранит область для пары «роль × право». Само право
 * остаётся в spatie: политики спрашивают «пустят ли вообще», область — «на
 * сколько записей». Разведи эти два вопроса по разным таблицам, и однажды
 * право будет, а области нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            // Дерево: отдел подчинён отделу. Корень — сама компания.
            $table->foreignId('parent_id')->nullable()->after('id')
                ->constrained('departments')->nullOnDelete();
            $table->unsignedInteger('sort')->default(0)->after('parent_id');
        });

        Schema::create('role_module_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            // Полное имя права: `deal.viewAny`. Не разбиваем на модуль и
            // действие — иначе пришлось бы склеивать их обратно в каждом
            // запросе, а права вроде `payroll.view` в схему не укладываются.
            $table->string('permission', 64);
            $table->string('scope', 24)->default('own');
            $table->timestamps();

            // Одна область на пару: дубль означал бы два разных ответа на один
            // вопрос, и какой из них применится, решал бы порядок строк.
            $table->unique(['role_id', 'permission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_module_access');
        Schema::table('departments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('sort');
        });
    }
};
