<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Вклад каждой сделки в строку плана.
 *
 * Нехватка по двум сделкам на один товар СКЛАДЫВАЕТСЯ в одну строку очереди —
 * так и задумано. Но «уже отправлено по этой сделке» считалось по
 * `production_plans.deal_id`, а он у строки один — первой сделки. Вторая
 * сделка своего вклада не видела и каждым нажатием «Добавить недостающее»
 * дописывала объём заново: 300 → 500 → 700 → 900.
 *
 * Теперь вклад лежит отдельной записью на пару (план, сделка); `deal_id` у
 * плана остаётся как «чей заказ показать мастеру».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_plan_deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['production_plan_id', 'deal_id'], 'production_plan_deals_unique');
        });

        // Что уже в очереди, приписываем сделке, которая строку завела: до
        // сих пор ровно так это и считалось.
        $now = now();
        DB::table('production_plans')->whereNotNull('deal_id')
            ->orderBy('id')->chunk(200, function ($plans) use ($now) {
                DB::table('production_plan_deals')->insert($plans->map(fn ($p) => [
                    'production_plan_id' => $p->id, 'deal_id' => $p->deal_id, 'qty' => $p->plan_qty,
                    'created_at' => $now, 'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_plan_deals');
    }
};
