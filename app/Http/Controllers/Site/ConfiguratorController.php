<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\CatalogService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 3D-конфигуратор двора: коллекции плитки, раскладки, палитра и расчёт
 * количества. Все цифры — из каталога ERP (цена, шт/м², цвета).
 */
class ConfiguratorController extends Controller
{
    /**
     * Раскладки: коэффициент подрезки на каждую.
     *
     * Ключ и процент — данные расчёта и живут в коде; название и подсказку
     * читает покупатель, поэтому они приходят из словаря. Константой список
     * быть перестал: в ней нельзя вызвать переводчик.
     */
    public const WASTE = [
        'running' => 5,
        'stack' => 3,
        'herringbone' => 12,
        'basket' => 7,
    ];

    /** @return array<int, array{key: string, name: string, waste: int, hint: string}> */
    public static function patterns(): array
    {
        return collect(self::WASTE)
            ->map(fn (int $waste, string $key) => [
                'key' => $key,
                'name' => __("site.configurator.patterns.$key.name"),
                'waste' => $waste,
                'hint' => __("site.configurator.patterns.$key.hint"),
            ])
            ->values()
            ->all();
    }

    public function __construct(private CatalogService $catalog) {}

    public function show(): Response
    {
        // Выключенный конфигуратор недоступен и по прямой ссылке.
        abort_unless(\App\Support\SiteContent::configuratorEnabled(), 404);

        return Inertia::render('Site/Configurator', [
            'collections' => $this->catalog->pavingCollections(),
            'patterns' => self::patterns(),
            'borders' => $this->catalog->products(['category' => 'bordyury'], 10)->items(),
            // featured() отдаёт уже переведённые записи массивами, поэтому
            // категория читается ключом, а не стрелкой.
            'accessories' => $this->catalog->featured(6)
                ->filter(fn (array $p) => ($p['category']['slug'] ?? null) !== 'trotuarnaya-plitka')->values(),
            'seo' => [
                'title' => __('site.seo.configurator_title'),
                'description' => __('site.seo.configurator_description'),
            ],
        ]);
    }
}
