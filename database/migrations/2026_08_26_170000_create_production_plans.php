<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * План производства: что бригада делает в этом месяце.
 *
 * До сих пор выработка бралась только из заказов клиентов — а цех работает и
 * на склад, между заказами. Такую работу вообще не считали: бригадир отливал
 * брусчатку впрок, а в системе месяц выглядел пустым.
 *
 * План ставит директор (или админ): сколько и какого товара сделать. Бригадир
 * видит свой план и отмечает выработку; подтверждает директор или финансист —
 * только после этого объём становится бонусом.
 *
 * Один план = ОДИН товар. «Брусчатка» — это категория, а делают и считают
 * конкретный артикул со своей единицей измерения.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            // Первое число месяца: план месячный, дата нужна только для группировки.
            $table->date('period_month');
            $table->foreignId('brigade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('plan_qty', 12, 2)->default(0);
            // Единица — снимок товара: переименуют каталог, а план останется
            // тем, что ставили.
            $table->string('unit')->nullable();
            // Ставка бонуса бригадиру за единицу. Пусто — действует общая из
            // настроек: владелец задаёт её один раз, а точечно правит планом.
            $table->decimal('bonus_rate', 12, 2)->nullable();
            $table->string('status')->default('active');   // active | closed
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Дубль плана удвоил бы и задание, и процент выполнения.
            $table->unique(['period_month', 'brigade_id', 'product_id'], 'production_plans_unique');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            // Второй возможный источник задания рядом с deal_item_id. У наряда
            // он ровно один: работа либо под заказ клиента, либо на склад.
            $table->foreignId('production_plan_id')->nullable()->after('deal_item_id')
                ->constrained('production_plans')->nullOnDelete();
            // Отклонение с причиной: «нет фото партии». Бригадиру должно быть
            // понятно, что исправить, — иначе он принесёт то же самое снова.
            $table->string('reject_reason')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            if (Schema::hasColumn('work_orders', 'production_plan_id')) {
                $table->dropConstrainedForeignId('production_plan_id');
            }
            if (Schema::hasColumn('work_orders', 'reject_reason')) {
                $table->dropColumn('reject_reason');
            }
        });
        Schema::dropIfExists('production_plans');
    }
};
