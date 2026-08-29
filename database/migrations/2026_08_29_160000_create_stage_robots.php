<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Роботы этапов — автоматизация поверх логики переходов.
 *
 * Робот привязан к этапу и событию (вход/выход), проверяет условия по полям
 * сделки и выполняет действие: сразу или через N секунд, параллельно или по
 * цепочке. Логику самих переходов не трогает — только слушает событие.
 * Запуски пишутся в журнал; пара (робот, переход) уникальна — повторно
 * робот на тот же переход не сработает.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_robots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stage_id')->nullable()->constrained('deal_stages')->cascadeOnDelete();
            $table->string('trigger', 10)->default('enter');      // enter | leave
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->string('sequence', 12)->default('parallel');  // parallel | sequential
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedInteger('delay_seconds')->default(0);
            $table->boolean('run_if_left')->default(false);
            $table->json('conditions')->nullable();
            $table->string('action_type', 40);
            $table->json('action_payload')->nullable();
            $table->timestamps();
            $table->index(['stage_id', 'trigger', 'is_active']);
        });

        Schema::create('stage_robot_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('robot_id')->constrained('stage_robots')->cascadeOnDelete();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->uuid('transition_id');
            $table->foreignId('stage_id_at_trigger')->nullable()->constrained('deal_stages')->nullOnDelete();
            $table->string('status', 10)->default('queued');   // queued | waiting | running | done | skipped | failed
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedTinyInteger('attempt')->default(0);
            $table->text('error')->nullable();
            $table->json('output')->nullable();
            $table->timestamps();
            $table->unique(['robot_id', 'transition_id']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stage_robot_runs');
        Schema::dropIfExists('stage_robots');
    }
};
