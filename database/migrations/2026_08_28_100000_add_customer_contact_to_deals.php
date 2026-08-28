<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заказчик и контакт прямо в сделке: БИН/ИИН, имя и телефон.
 *
 * Раньше эти три поля жили только в заявке и при «В работу ✓» падали абзацем
 * в «Заметку» — искать телефон в тексте посреди рабочего дня менеджеру
 * приходилось глазами. Заявки больше нет, и полям место в самой сделке.
 *
 * Новые колонки, а НЕ существующие `bin` и `client_name`: у сделки они давно
 * значат другое — «Номер договора» и «Наименование товара» (исторические
 * имена, которые правит только UI). Переиспользуй их — и номер договора
 * перезаписался бы БИНом на первой же сделке.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('customer_bin', 32)->nullable()->after('bin');
            $table->string('contact_name')->nullable()->after('customer_bin');
            $table->string('contact_phone', 64)->nullable()->after('contact_name');
        });
    }

    public function down(): void
    {
        Schema::table('deals', fn (Blueprint $table) => $table->dropColumn([
            'customer_bin', 'contact_name', 'contact_phone',
        ]));
    }
};
