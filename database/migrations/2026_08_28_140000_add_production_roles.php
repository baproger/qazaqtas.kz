<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Роли «Начальник производства» и «Ассистент» действующим базам.
 *
 * Сидер заводит их на чистой установке; здесь — досев там, где система уже
 * работает. Права не раздаём: их владелец разложит в Настройки → Права
 * доступа, а признаки роли ставим сразу, иначе роль родилась бы немой.
 */
return new class extends Migration
{
    public function up(): void
    {
        $traits = [
            'production_head' => ['Начальник производства', false, false, true],
            'assistant' => ['Ассистент', true, false, false],
        ];

        foreach ($traits as $name => [$label, $leadership, $money, $workshop]) {
            $exists = DB::table('roles')->where('name', $name)->exists();

            if (! $exists) {
                DB::table('roles')->insert([
                    'name' => $name, 'guard_name' => 'web',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('roles')->where('name', $name)->update([
                'label' => $label,
                'is_leadership' => $leadership,
                'sees_money' => $money,
                'is_workshop' => $workshop,
                'is_system' => true,
            ]);
        }
    }

    /** Роли не удаляем: на них уже могли назначить людей. */
    public function down(): void {}
};
