<?php

namespace Database\Seeders;

use App\Models\CustomField;
use Illuminate\Database\Seeder;

class DealCustomFieldSeeder extends Seeder
{
    public function run(): void
    {
        $fields = [
            ['name' => 'Имя компании', 'type' => 'text'],
            ['name' => 'БИН / ИИН', 'type' => 'text'],
            ['name' => 'Адрес объекта', 'type' => 'text'],
            ['name' => 'Тип изделия', 'type' => 'select', 'options' => [
                'Тротуарная плитка', 'Бордюр', 'Вазон', 'Скамья', 'Урна',
                'Фонтан', 'Балясина / ограждение', 'Облицовка / подоконник', 'Другое',
            ]],
            ['name' => 'Цвет / пигмент', 'type' => 'text'],
            ['name' => 'Фактура поверхности', 'type' => 'select', 'options' => [
                'Глянцевая', 'Матовая', 'Шлифованная', 'Рустованная', 'Под камень',
            ]],
        ];

        foreach ($fields as $i => $f) {
            CustomField::updateOrCreate(
                ['entity_type' => 'deal', 'name' => $f['name']],
                [
                    'type' => $f['type'],
                    'required' => false,
                    'unique' => false,
                    'is_visible' => true,
                    'order' => $i + 1,
                    'options' => $f['options'] ?? null,
                ]
            );
        }
    }
}
