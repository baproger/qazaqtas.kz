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
            ['name' => 'БИН',          'type' => 'text'],
            ['name' => 'Адрес',        'type' => 'text'],
        ];

        foreach ($fields as $i => $f) {
            CustomField::updateOrCreate(
                ['entity_type' => 'deal', 'name' => $f['name']],
                ['type' => $f['type'], 'required' => false, 'unique' => false, 'is_visible' => true, 'order' => $i + 1, 'options' => null]
            );
        }
    }
}
