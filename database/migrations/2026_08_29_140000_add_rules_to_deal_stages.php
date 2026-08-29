<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Конструктор логики этапа.
 *
 * Раньше логика этапа целиком сидела в «системном типе» — закрытом списке из
 * восьми значений, зашитых в код. Теперь у этапа есть `rules` — набор
 * независимых правил (кто двигает, откуда можно прийти, что должно быть
 * выполнено, кого уведомить), которые владелец собирает сам.
 *
 * Пусто (NULL) — правила выводятся из типа, как было (DealStage::effectiveRules):
 * старые воронки и тесты работают без изменений, пока владелец не откроет
 * этап и не сохранит его — тогда правила ложатся явно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_stages', function (Blueprint $table) {
            $table->json('rules')->nullable()->after('requires_document');
        });
    }

    public function down(): void
    {
        Schema::table('deal_stages', function (Blueprint $table) {
            $table->dropColumn('rules');
        });
    }
};
