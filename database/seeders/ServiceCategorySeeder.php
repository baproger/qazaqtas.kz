<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Стартовые категории услуг — владелец дополнит через БД/админку. */
class ServiceCategorySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Укладка и монтаж', 'Доставка', 'Проектирование и дизайн', 'Обслуживание и уход', 'Аренда техники'] as $i => $name) {
            ServiceCategory::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name, 'sort' => $i + 1, 'is_active' => true]);
        }
    }
}
