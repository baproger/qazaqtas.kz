<?php

namespace Database\Seeders;

use App\Models\SiteProject;
use App\Support\SiteContent;
use Illuminate\Database\Seeder;

/**
 * Стартовые объекты берём из значений по умолчанию SiteContent — дальше они
 * правятся в ERP → Объекты сайта, туда же грузятся фотографии.
 */
class SiteProjectSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SiteContent::projects() as $i => $row) {
            SiteProject::updateOrCreate(
                ['title' => $row['title']],
                [
                    'city' => $row['city'] ?? null,
                    'year' => $row['year'] ?? null,
                    'area' => $row['area'] ?? null,
                    'products' => $row['products'] ?? null,
                    'order' => $i + 1,
                    'is_active' => true,
                ]
            );
        }
    }
}
