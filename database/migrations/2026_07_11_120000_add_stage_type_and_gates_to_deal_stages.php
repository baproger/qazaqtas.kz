<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Спец-этапы больше не ищутся по названию: явный stage_type (act, esf,
     * logistics, shop_gate, payment_won, …) + настраиваемые гейт-задачи
     * (текст, роль исполнителя, срок в днях) вместо захардкоженных
     * «Выставить акт» (3 дня) и «Выставить ЭСФ» (30 дней).
     */
    public function up(): void
    {
        Schema::table('deal_stages', function (Blueprint $table) {
            $table->string('stage_type', 30)->nullable()->index()->after('type');
            $table->string('gate_task_title')->nullable()->after('checklist');
            $table->string('gate_task_role', 50)->nullable()->after('gate_task_title');
            $table->unsignedSmallInteger('gate_task_days')->nullable()->after('gate_task_role');
        });

        // Одноразовый бэкофилл по текущим названиям (как искала старая логика).
        $map = [
            'акт' => 'act',
            'эсф' => 'esf',
            'логист' => 'logistics',
            'закуп' => 'shop_gate',
            'договор' => 'contract',
            'дизайн' => 'design',
            'сборк' => 'assembly',
        ];
        foreach (DB::table('deal_stages')->get(['id', 'name', 'is_won']) as $s) {
            $type = $s->is_won ? 'payment_won' : null;
            if (! $type) {
                foreach ($map as $needle => $t) {
                    if (mb_stripos($s->name, $needle) !== false) {
                        $type = $t;
                        break;
                    }
                }
            }
            if ($type) {
                DB::table('deal_stages')->where('id', $s->id)->update(['stage_type' => $type]);
            }
        }

        // Гейты как раньше: Акт → задача бухгалтеру 3 дня, ЭСФ → 30 дней.
        DB::table('deal_stages')->where('stage_type', 'act')->update([
            'gate_task_title' => 'Выставить акт', 'gate_task_role' => 'financist', 'gate_task_days' => 3,
        ]);
        DB::table('deal_stages')->where('stage_type', 'esf')->update([
            'gate_task_title' => 'Выставить ЭСФ', 'gate_task_role' => 'financist', 'gate_task_days' => 30,
        ]);
    }

    public function down(): void
    {
        Schema::table('deal_stages', function (Blueprint $table) {
            $table->dropColumn(['stage_type', 'gate_task_title', 'gate_task_role', 'gate_task_days']);
        });
    }
};
