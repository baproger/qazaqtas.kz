<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Предварительные сделки (лоты, как Excel-файл отдела продаж): менеджер вносит
 * цифры, система считает партнёра/налог/остаток/маржу. Маржа ≥ 15% — «участвую»,
 * можно подтвердить в полноценную сделку; ниже — отклоняется. Чек-лист действий
 * (КП в WhatsApp, звонок клиенту…) настраивается админом/финансистом.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_deal_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pre_deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // менеджер
            $table->string('lot_number', 100)->nullable();
            $table->string('bin', 40)->nullable();            // БИН заказчика
            $table->string('customer')->nullable();           // заказчик (компания)
            $table->string('client_name')->nullable();        // контакт (имя)
            $table->string('client_phone', 40)->nullable();   // контакт (телефон)
            $table->string('product');                        // название товара
            $table->decimal('contract_sum', 15, 2)->default(0);
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->decimal('partner_pct', 5, 2)->default(0);
            $table->decimal('partner_sum', 15, 2)->default(0);
            $table->decimal('delivery', 15, 2)->default(0);
            $table->decimal('commission', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('remainder', 15, 2)->default(0);
            $table->decimal('margin', 8, 2)->default(0);
            $table->json('checks')->nullable();               // {item_id: true}
            $table->string('status', 20)->default('new');     // new | confirmed
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index('created_at');
        });

        // Стартовый чек-лист (дальше правится в UI админом/финансистом).
        foreach (['Отправлено КП через WhatsApp', 'Позвонил клиенту'] as $i => $label) {
            DB::table('pre_deal_checklist_items')->insert([
                'label' => $label, 'order' => $i + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_deals');
        Schema::dropIfExists('pre_deal_checklist_items');
    }
};
