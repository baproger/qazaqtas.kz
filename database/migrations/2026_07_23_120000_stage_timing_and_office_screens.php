<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1) Тайминг этапов цеха: project_stage_logs — когда заказ вошёл на этап,
 *    когда ушёл и сколько провёл (для таймера на канбане/ТВ и истории).
 *    Для текущих активных заказов открываем лог задним числом (updated_at).
 * 2) Экраны: kind = workshop | office («Офис» — сделки и лидеры менеджеров).
 *
 * Все шаги идемпотентны: на MySQL DDL не транзакционен, при падении миграция
 * может примениться частично. Новый unique добавляется ДО удаления старого —
 * оба начинаются с company_id, поэтому FK-индекс не теряется (ошибка 1553).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_stage_logs')) {
            Schema::create('project_stage_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->foreignId('project_stage_id')->nullable()->constrained()->nullOnDelete();
                $table->string('stage_name');
                $table->dateTime('entered_at');
                $table->dateTime('left_at')->nullable();
                $table->unsignedBigInteger('duration_seconds')->nullable();
                $table->timestamps();
                $table->index(['project_id', 'left_at']);
            });
        }

        if (! Schema::hasColumn('workshop_screens', 'kind')) {
            Schema::table('workshop_screens', fn (Blueprint $t) => $t->string('kind', 20)->default('workshop')->after('workshop'));
        }
        try {
            Schema::table('workshop_screens', fn (Blueprint $t) => $t->unique(['company_id', 'workshop', 'kind']));
        } catch (\Throwable) {
            // уникальный индекс уже есть
        }
        try {
            Schema::table('workshop_screens', fn (Blueprint $t) => $t->dropUnique(['company_id', 'workshop']));
        } catch (\Throwable) {
            // старый индекс уже удалён
        }

        // Активные заказы: открываем лог текущего этапа задним числом (один раз).
        if (DB::table('project_stage_logs')->count() === 0) {
            DB::table('projects')->whereNotIn('status', ['completed', 'cancelled'])
                ->whereNotNull('project_stage_id')->get(['id', 'project_stage_id', 'updated_at'])
                ->each(function ($p) {
                    $name = DB::table('project_stages')->where('id', $p->project_stage_id)->value('name') ?? '—';
                    DB::table('project_stage_logs')->insert([
                        'project_id' => $p->id, 'project_stage_id' => $p->project_stage_id,
                        'stage_name' => $name, 'entered_at' => $p->updated_at ?? now(),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                });
        }
    }

    public function down(): void
    {
        Schema::table('workshop_screens', function (Blueprint $table) {
            $table->unique(['company_id', 'workshop']);
            $table->dropUnique(['company_id', 'workshop', 'kind']);
            $table->dropColumn('kind');
        });
        Schema::dropIfExists('project_stage_logs');
    }
};
