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
                'title' => __('site.seo.home_title'),
                'description' => __('site.seo.home_description'),
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

        return SiteProject::active()->with('translations')
            ->orderBy('order')->orderByDesc('id')->get()
            ->map->localized();
    }

    public function about(): Response
    {
        return Inertia::render('Site/About', [
            'stats' => SiteContent::stats(),
            'production' => SiteContent::production(),
            'advantages' => SiteContent::advantages(),
            'seo' => [
                'title' => __('site.seo.about_title'),
                'description' => __('site.seo.about_description'),
            ],
        ]);
    }

    public function projects(): Response
    {
        return Inertia::render('Site/Projects', [
            'projects' => $this->projectList(),
            'seo' => [
                'title' => __('site.seo.projects_title'),
                'description' => __('site.seo.projects_description'),
            ],
        ]);
    }

    public function contacts(): Response
    {
        return Inertia::render('Site/Contacts', [
            'faq' => SiteContent::faq(),
            'seo' => [
                'title' => __('site.seo.contacts_title'),
                'description' => __('site.seo.contacts_description'),
            ],
        ]);
    }
}
