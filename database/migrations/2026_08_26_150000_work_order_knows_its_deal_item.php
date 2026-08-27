<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Наряд знает, какую позицию сделки он закрывает.
 *
 * До этого в наряде было свободное поле «изделие» текстом — и связи с
 * заказом не было никакой: бригадир не знал, сколько по сделке осталось, а
 * руководство не видело, закрыт ли объём. Одно и то же изделие писали то
 * «Плитка 300х300», то «плитка 300*300», и сложить это было нельзя.
 *
 * Привязываем к ПОЗИЦИИ, а не к сделке: план в м² живёт именно в строке
 * заказа («Плитка «Ромб» — 210 м²»). В одной сделке позиции бывают в разных
 * единицах, и общий план по сделке одним числом не считается.
 *
 * nullSet при удалении позиции: наряд — это уже начисленные деньги, он
 * обязан пережить правку заказа. Потеряется только привязка.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('deal_item_id')->nullable()->after('project_id')
                ->constrained('deal_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deal_item_id');
        });
    }
};
