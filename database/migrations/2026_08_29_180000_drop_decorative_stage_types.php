<?php

use App\Models\DealStage;
use Illuminate\Database\Migrations\Migration;

/**
 * Метки contract / design / assembly ничего не делали в коде (design — только
 * «технолог может нажать Далее», это теперь условие перехода). Переносим их
 * правила в `rules` и снимаем метку, чтобы список «Роль в процессе» содержал
 * только то, на что опирается система.
 */
return new class extends Migration
{
    public function up(): void
    {
        DealStage::whereIn('stage_type', ['contract', 'design', 'assembly'])->get()->each(function (DealStage $s) {
            $s->rules = $s->effectiveRules();
            $s->stage_type = null;
            $s->save();
        });
    }

    public function down(): void {}
};
