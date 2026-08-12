<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\CatalogService;
use App\Models\SiteProject;
use App\Support\SiteContent;
use Inertia\Inertia;
use Inertia\Response;

/** Витринные страницы: главная, о заводе, производство, проекты, контакты. */
class PageController extends Controller
{
    public function __construct(private CatalogService $catalog) {}

    public function home(): Response
    {
        return Inertia::render('Site/Home', [
            'categories' => $this->catalog->categories(),
            'featured' => $this->catalog->featured(8),
            'paving' => $this->catalog->pavingCollections(),
            // Оформление первого экрана и слайды витрины. Слайды считаются
            // всегда: переключение в настройках не должно требовать чистки кэша.
            'hero' => SiteContent::heroStyle(),
            'heroSlides' => $this->catalog->heroSlides(),
            // Фото-текстуры и GLB-модели для 3D-сцены (если загружены в ERP).
            'scene' => $this->catalog->sceneAssets(),
            'stats' => SiteContent::stats(),
            'advantages' => SiteContent::advantages(),
            'production' => SiteContent::production(),
            'projects' => $this->projectList(),
            'seo' => [
                'title' => 'QAZAQ TAS — тротуарная плитка и малые архитектурные формы из мраморного композита',
                'description' => 'Производство тротуарной плитки, бордюров, вазонов, скамей и урн из мраморного композита. Три площадки: Шымкент, Алматы, Тараз. Расчёт, доставка и монтаж по Казахстану.',
            ],
        ]);
    }

    /**
     * Объекты берём из ERP. Стартовый набор из настроек подставляем ТОЛЬКО
     * пока таблица вообще пуста: если объекты заведены, но все скрыты —
     * это осознанное решение владельца, и подменять его нельзя.
     */
    private function projectList(): \Illuminate\Support\Collection
    {
        if (! SiteProject::exists()) {
            return collect(SiteContent::projects());
        }

        return SiteProject::active()->orderBy('order')->orderByDesc('id')->get();
    }

    public function about(): Response
    {
        return Inertia::render('Site/About', [
            'stats' => SiteContent::stats(),
            'production' => SiteContent::production(),
            'advantages' => SiteContent::advantages(),
            'seo' => [
                'title' => 'О заводе QAZAQ TAS — производство мраморного композита',
                'description' => 'Как устроено производство QAZAQ TAS: сырьё, вибролитьё, выдержка, шлифовка и контроль качества на трёх площадках.',
            ],
        ]);
    }

    public function projects(): Response
    {
        return Inertia::render('Site/Projects', [
            'projects' => $this->projectList(),
            'seo' => [
                'title' => 'Реализованные проекты QAZAQ TAS',
                'description' => 'Дворы, парки, набережные и школьные территории, благоустроенные изделиями QAZAQ TAS.',
            ],
        ]);
    }

    public function contacts(): Response
    {
        return Inertia::render('Site/Contacts', [
            'faq' => SiteContent::faq(),
            'seo' => [
                'title' => 'Контакты QAZAQ TAS — Шымкент, Алматы, Тараз',
                'description' => 'Адреса производств, телефоны отдела продаж и ответы на частые вопросы о доставке, сроках и гарантии.',
            ],
        ]);
    }
}
