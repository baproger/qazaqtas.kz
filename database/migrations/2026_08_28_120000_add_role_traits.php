<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Признаки роли: чем роль ЯВЛЯЕТСЯ, а не только что ей разрешено.
 *
 * Права отвечают на вопрос «пустят ли в раздел». Но система спрашивает роль и
 * о другом: показывать ли суммы, видит ли она все сделки или только свои,
 * пускать ли на доску цеха. Раньше ответы были зашиты именами ролей в сотне
 * мест (`hasAnyRole(['admin','director','financist'])`), и новая роль,
 * созданная владельцем, оказывалась немой: галочки есть, поведения нет.
 *
 * Флаги ЗАСЕИВАЮТСЯ ТОЧНО ПОД СЕГОДНЯШНЕЕ ПОВЕДЕНИЕ десяти существующих
 * ролей — переход не должен изменить систему ни на шаг. Меняется только то,
 * что теперь это можно настроить.
 *
 * `label` — человеческое имя роли. Имя строки (`financist`) остаётся кодом:
 * на нём держатся политики и запасные проверки, и переименование не должно
 * их ронять (тот же принцип, что у `stage_type`, §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('label')->nullable()->after('name');
            // Руководство: видит ВСЕ сделки и общую аналитику, а не только свои.
            $table->boolean('is_leadership')->default(false)->after('label');
            // Видит суммы: договор, расходы, бонус. Цеху их не показывают.
            $table->boolean('sees_money')->default(true)->after('is_leadership');
            // Цеховая роль: доска заказов и карточки цеха.
            $table->boolean('is_workshop')->default(false)->after('sees_money');
            // Системная роль: заведена сидером, удалять нельзя — на её имени
            // держатся политики. Переименовать (label) можно.
            $table->boolean('is_system')->default(false)->after('is_workshop');
        });

        // Снимок сегодняшнего поведения. Роли, которых нет, просто не тронутся.
        // is_workshop — ТОЛЬКО про цеховой персонал. Руководство на доску цеха
        // и так пускают (правило «руководство ИЛИ цех»), и ставить ему этот
        // флаг значило бы объявить директора работником цеха.
        // Юрист и повар — уровень «сотрудник», но доски цеха у них нет
        // (ProjectPolicy их не перечисляет): им видны только свои заказы.
        $traits = [
            'admin' => ['СЕО (админ)', true, true, false],
            'director' => ['Директор', true, true, false],
            'financist' => ['Финансист-Бухгалтер', true, true, false],
            'manager' => ['Менеджер', false, true, false],
            'employee' => ['Сотрудник цеха', false, false, true],
            'foreman' => ['Бригадир', false, false, true],
            'designer' => ['Технолог', false, true, false],
            'supplier' => ['Снабженец', false, true, false],
            'lawyer' => ['Юрист', false, false, false],
            'cook' => ['Повар', false, false, false],
        ];

        foreach ($traits as $name => [$label, $leadership, $money, $workshop]) {
            DB::table('roles')->where('name', $name)->update([
                'label' => $label,
                'is_leadership' => $leadership,
                'sees_money' => $money,
                'is_workshop' => $workshop,
                'is_system' => true,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn([
            'label', 'is_leadership', 'sees_money', 'is_workshop', 'is_system',
        ]));
    }
};
