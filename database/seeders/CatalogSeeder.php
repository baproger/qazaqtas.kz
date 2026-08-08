<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

/**
 * Стартовый каталог QAZAQ TAS. Дальше карточки правятся в ERP → Каталог,
 * сайт всегда показывает то, что лежит здесь — отдельной базы у витрины нет.
 *
 * pieces_per_m2 в specs — основа калькуляторов площади и конфигуратора.
 */
class CatalogSeeder extends Seeder
{
    /** Палитра мраморного композита — общая для изделий. */
    private const PALETTE = [
        ['name' => 'Мрамор белый', 'hex' => '#E8E6E1'],
        ['name' => 'Песочный', 'hex' => '#D8C3A0'],
        ['name' => 'Серый графит', 'hex' => '#8A8D91'],
        ['name' => 'Антрацит', 'hex' => '#3F4448'],
        ['name' => 'Терракота', 'hex' => '#A5563C'],
        ['name' => 'Изумруд', 'hex' => '#4A6B5B'],
    ];

    public function run(): void
    {
        $categories = [
            ['name' => 'Тротуарная плитка', 'slug' => 'trotuarnaya-plitka', 'accent' => '#B9B3A9',
                'tagline' => 'Мраморный композит для дворов, парков и городских улиц',
                'description' => 'Вибролитая плитка из мраморного композита: плотная структура, низкое водопоглощение и цвет, который не выгорает. Подходит для пешеходных зон и проездов.'],
            ['name' => 'Бордюры', 'slug' => 'bordyury', 'accent' => '#9AA1A6',
                'tagline' => 'Ровный край дорожки, газона и проезжей части',
                'description' => 'Дорожные и садовые бордюры одной палитры с плиткой — переход между покрытиями выглядит цельно.'],
            ['name' => 'Вазоны', 'slug' => 'vazony', 'accent' => '#C2A98B',
                'tagline' => 'Уличные кашпо для благоустройства',
                'description' => 'Вазоны из мраморного композита: держат форму и цвет под солнцем и морозом, подходят для улиц, ТРЦ и входных групп.'],
            ['name' => 'Скамьи', 'slug' => 'skami', 'accent' => '#8C7F70',
                'tagline' => 'Парковые и городские скамьи',
                'description' => 'Скамьи с основанием из композита и деревянным сиденьем либо цельнолитые — для парков, набережных и дворов.'],
            ['name' => 'Урны', 'slug' => 'urny', 'accent' => '#7C8286',
                'tagline' => 'Уличные урны в единой линейке',
                'description' => 'Урны из мраморного композита с внутренним вкладышем — собираются в комплект со скамьями и вазонами.'],
            ['name' => 'Ступени и облицовка', 'slug' => 'stupeni-oblicovka', 'accent' => '#A79C8E',
                'tagline' => 'Лестницы, подоконники, фасадные элементы',
                'description' => 'Ступени, подступёнки, подоконники и облицовочные плиты — те же цвета и фактуры, что и у покрытия.'],
        ];

        foreach ($categories as $i => $c) {
            ProductCategory::updateOrCreate(['slug' => $c['slug']], $c + ['order' => $i + 1, 'is_active' => true]);
        }

        $ids = ProductCategory::pluck('id', 'slug');

        $products = [
            // --- Тротуарная плитка ---
            ['trotuarnaya-plitka', 'Плитка «Квадрат» 300×300×60', 'QT-P-300', 'м²', 8900, [
                'size' => '300 × 300 × 60 мм', 'thickness_mm' => 60, 'pieces_per_m2' => 11.1,
                'weight_kg_m2' => 132, 'frost' => 'F200', 'strength' => 'B30', 'water' => '≤ 3 %',
            ], true],
            ['trotuarnaya-plitka', 'Плитка «Кирпичик» 200×100×60', 'QT-P-201', 'м²', 9400, [
                'size' => '200 × 100 × 60 мм', 'thickness_mm' => 60, 'pieces_per_m2' => 50,
                'weight_kg_m2' => 135, 'frost' => 'F200', 'strength' => 'B30', 'water' => '≤ 3 %',
            ], true],
            ['trotuarnaya-plitka', 'Плитка «Ромб» 190×330×60', 'QT-P-193', 'м²', 11200, [
                'size' => '190 × 330 × 60 мм', 'thickness_mm' => 60, 'pieces_per_m2' => 18,
                'weight_kg_m2' => 138, 'frost' => 'F200', 'strength' => 'B30', 'water' => '≤ 3 %',
            ], true],
            ['trotuarnaya-plitka', 'Плитка «Соты» 265×230×60', 'QT-P-265', 'м²', 11800, [
                'size' => '265 × 230 × 60 мм', 'thickness_mm' => 60, 'pieces_per_m2' => 20,
                'weight_kg_m2' => 140, 'frost' => 'F200', 'strength' => 'B30', 'water' => '≤ 3 %',
            ], false],
            ['trotuarnaya-plitka', 'Плитка «Большой формат» 600×300×80', 'QT-P-603', 'м²', 15600, [
                'size' => '600 × 300 × 80 мм', 'thickness_mm' => 80, 'pieces_per_m2' => 5.5,
                'weight_kg_m2' => 176, 'frost' => 'F250', 'strength' => 'B35', 'water' => '≤ 2,5 %',
            ], false],

            // --- Бордюры ---
            ['bordyury', 'Бордюр дорожный 1000×300×150', 'QT-B-1000', 'п.м.', 6400, [
                'size' => '1000 × 300 × 150 мм', 'weight_kg' => 98, 'frost' => 'F200', 'strength' => 'B30',
            ], true],
            ['bordyury', 'Бордюр садовый 1000×200×80', 'QT-B-800', 'п.м.', 3800, [
                'size' => '1000 × 200 × 80 мм', 'weight_kg' => 38, 'frost' => 'F200', 'strength' => 'B30',
            ], false],
            ['bordyury', 'Водосток 500×160×60', 'QT-B-500', 'п.м.', 4200, [
                'size' => '500 × 160 × 60 мм', 'weight_kg' => 12, 'frost' => 'F200', 'strength' => 'B30',
            ], false],

            // --- Вазоны ---
            ['vazony', 'Вазон «Астана» Ø900', 'QT-V-900', 'шт', 78000, [
                'size' => 'Ø 900 × 700 мм', 'volume_l' => 210, 'weight_kg' => 160, 'frost' => 'F200',
            ], true],
            ['vazony', 'Вазон «Куб» 600×600×600', 'QT-V-600', 'шт', 52000, [
                'size' => '600 × 600 × 600 мм', 'volume_l' => 120, 'weight_kg' => 95, 'frost' => 'F200',
            ], false],
            ['vazony', 'Вазон «Чаша» Ø1200', 'QT-V-1200', 'шт', 124000, [
                'size' => 'Ø 1200 × 550 мм', 'volume_l' => 320, 'weight_kg' => 240, 'frost' => 'F200',
            ], false],

            // --- Скамьи ---
            ['skami', 'Скамья «Парковая» 1800', 'QT-S-1800', 'шт', 96000, [
                'size' => '1800 × 600 × 800 мм', 'seat' => 'Лиственница, масло', 'weight_kg' => 180,
            ], true],
            ['skami', 'Скамья «Сквер» 1500 без спинки', 'QT-S-1500', 'шт', 74000, [
                'size' => '1500 × 450 × 450 мм', 'seat' => 'Композит', 'weight_kg' => 155,
            ], false],

            // --- Урны ---
            ['urny', 'Урна «Сити» 40 л', 'QT-U-40', 'шт', 42000, [
                'size' => 'Ø 400 × 700 мм', 'volume_l' => 40, 'weight_kg' => 62, 'insert' => 'Оцинкованный вкладыш',
            ], true],

            // --- Ступени и облицовка ---
            ['stupeni-oblicovka', 'Ступень накладная 1000×330×40', 'QT-ST-1000', 'п.м.', 14500, [
                'size' => '1000 × 330 × 40 мм', 'weight_kg' => 33, 'surface' => 'Шлифованная',
            ], true],
            ['stupeni-oblicovka', 'Подоконник 1000×300×30', 'QT-ST-300', 'п.м.', 12800, [
                'size' => '1000 × 300 × 30 мм', 'weight_kg' => 22, 'surface' => 'Полированная',
            ], false],
        ];

        foreach ($products as $i => [$categorySlug, $name, $code, $unit, $price, $specs, $featured]) {
            Product::updateOrCreate(
                ['code' => $code],
                [
                    'category_id' => $ids[$categorySlug] ?? null,
                    'name' => $name,
                    'unit' => $unit,
                    'price' => $price,
                    'min_order' => $unit === 'м²' ? 10 : 1,
                    'short_description' => $specs['size'].' · мраморный композит',
                    'description' => 'Изделие из мраморного композита собственного производства QAZAQ TAS. '
                        .'Вибролитьё, армирование фиброволокном, сквозное окрашивание — цвет не стирается и не выгорает. '
                        .'Производство в Шымкенте, Алматы и Таразе, отгрузка по всему Казахстану.',
                    'specs' => $specs,
                    'colors' => self::PALETTE,
                    'images' => [],
                    'documents' => [],
                    'is_service' => false,
                    'is_active' => true,
                    'is_featured' => $featured,
                    'in_stock' => true,
                    'order' => $i + 1,
                ]
            );
        }
    }
}
