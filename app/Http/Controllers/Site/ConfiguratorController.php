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
    /** Раскладки: шаг паттерна и коэффициент подрезки. */
    public const PATTERNS = [
        ['key' => 'running', 'name' => 'Со смещением', 'waste' => 5, 'hint' => 'Классическая перевязка на пол-элемента'],
        ['key' => 'stack', 'name' => 'Шов в шов', 'waste' => 3, 'hint' => 'Строгая сетка, ровные линии'],
        ['key' => 'herringbone', 'name' => 'Ёлочка', 'waste' => 12, 'hint' => 'Диагональная укладка, максимальная стойкость к сдвигу'],
        ['key' => 'basket', 'name' => 'Плетёнка', 'waste' => 7, 'hint' => 'Парные блоки, рисунок «корзинка»'],
    ];

    public function __construct(private CatalogService $catalog) {}

    public function show(): Response
    {
        return Inertia::render('Site/Configurator', [
            'collections' => $this->catalog->pavingCollections(),
            'patterns' => self::PATTERNS,
            'borders' => $this->catalog->products(['category' => 'bordyury'], 10)->items(),
            'accessories' => $this->catalog->featured(6)
                ->filter(fn ($p) => $p->category?->slug !== 'trotuarnaya-plitka')->values(),
            'seo' => [
                'title' => '3D-конфигуратор двора — QAZAQ TAS',
                'description' => 'Подберите плитку, цвет и раскладку, посмотрите результат в 3D и получите расчёт количества и стоимости.',
            ],
        ]);
    }
}
