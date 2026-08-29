<?php

use App\Models\DealStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Акт» и «ЭСФ» были именами из нашего бизнеса, зашитыми в систему. Для
 * другого бизнеса этапы называются иначе, а смысл тот же — «сделка почти
 * закрыта». Поэтому вместо двух именованных меток — два свойства этапа:
 *   is_closing       — финальная проверка: «на подходе» в отчётах и ЗП,
 *                      карточку правит только бухгалтер/админ;
 *   ignores_deadline — не считать просроченной (раньше — только ЭСФ).
 * Правила «кто двигает / откуда» уже живут в `rules` — материализуем их до
 * снятия меток, чтобы поведение не изменилось.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_stages', function (Blueprint $table) {
            $table->boolean('is_closing')->default(false)->after('is_won');
            $table->boolean('ignores_deadline')->default(false)->after('is_closing');
        });

        // Сначала правила (они смотрят на типы соседей), потом флаги и снятие меток.
        $stages = DealStage::whereIn('stage_type', ['act', 'esf', 'payment_won'])->get();
        foreach ($stages as $s) {
            if ($s->rules === null) {
                $s->rules = $s->effectiveRules();
                $s->saveQuietly();
            }
        }
        foreach ($stages as $s) {
            if ($s->stage_type === 'payment_won') {
                continue;
            }
            $s->is_closing = true;
            $s->ignores_deadline = $s->stage_type === 'esf';
            $s->stage_type = null;
            $s->saveQuietly();
        }
    }

    public function down(): void
    {
        Schema::table('deal_stages', function (Blueprint $table) {
            $table->dropColumn(['is_closing', 'ignores_deadline']);
        });
    }
};
