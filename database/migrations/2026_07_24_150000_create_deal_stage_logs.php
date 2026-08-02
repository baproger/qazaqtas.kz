<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * История этапов сделки: когда вошла на этап, когда ушла, сколько провела и
 * кто перевёл — как project_stage_logs у цеха. Для активных сделок открываем
 * лог текущего этапа задним числом (updated_at), чтобы история началась сразу.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deal_stage_logs')) {
            Schema::create('deal_stage_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
                $table->foreignId('deal_stage_id')->nullable()->constrained()->nullOnDelete();
                $table->string('stage_name');
                $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('entered_at');
                $table->dateTime('left_at')->nullable();
                $table->unsignedBigInteger('duration_seconds')->nullable();
                $table->timestamps();
                $table->index(['deal_id', 'left_at']);
            });
        }

        // Бэкфилл (идемпотентный): активным сделкам — открытый лог текущего этапа.
        $stageNames = DB::table('deal_stages')->pluck('name', 'id');
        DB::table('deals')->whereNull('deleted_at')->where('status', 'active')->whereNotNull('deal_stage_id')
            ->orderBy('id')->get(['id', 'deal_stage_id', 'updated_at'])
            ->each(function ($d) use ($stageNames) {
                if (! DB::table('deal_stage_logs')->where('deal_id', $d->id)->exists()) {
                    DB::table('deal_stage_logs')->insert([
                        'deal_id' => $d->id,
                        'deal_stage_id' => $d->deal_stage_id,
                        'stage_name' => $stageNames[$d->deal_stage_id] ?? '—',
                        'entered_at' => $d->updated_at ?? now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_stage_logs');
    }
};
