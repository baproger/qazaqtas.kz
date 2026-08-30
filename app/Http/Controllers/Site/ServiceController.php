<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Support\Seo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Публичный каталог услуг: только одобренные. */
class ServiceController extends Controller
{
    public function index(Request $request): Response
    {
        $category = $request->string('category')->toString();
        $search = trim($request->string('search')->toString());

        $services = Service::approved()->with(['translations', 'category:id,name,slug', 'category.translations'])
            ->when($category !== '', fn ($q) => $q->whereHas('category', fn ($c) => $c->where('slug', $category)))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%")->orWhere('city', 'like', "%{$search}%")))
            ->latest('moderated_at')->paginate(24)->withQueryString()
            ->through(fn (Service $s) => $s->toCard());

        return Inertia::render('Site/Services', [
            'services' => $services,
            'categories' => ServiceCategory::where('is_active', true)->orderBy('sort')
                ->with('translations')->withCount(['services as n' => fn ($q) => $q->approved()])->get()
                ->filter(fn ($c) => $c->n > 0)
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->tr('name'), 'slug' => $c->slug, 'n' => $c->n])->values(),
            'filters' => ['category' => $category, 'search' => $search],
            'seo' => Seo::for(null, __('site.services.seo_title'), __('site.services.seo_description'), null, route('site.services')),
        ]);
    }

    public function show(string $slug): Response
    {
        $service = Service::approved()->where('slug', $slug)->with(['translations', 'category:id,name,slug', 'category.translations'])->firstOrFail();

        return Inertia::render('Site/Service', [
            'service' => $service->toCard() + [
                'description_full' => $service->tr('description'),
                'contact_name' => $service->contact_name,
                'contact_phone' => $service->contact_phone,
                'published_at' => $service->moderated_at?->toDateString(),
            ],
            'related' => Service::approved()->where('id', '!=', $service->id)
                ->where('category_id', $service->category_id)->with('translations')->latest('moderated_at')->limit(3)->get()
                ->map(fn (Service $s) => $s->toCard()),
            'seo' => Seo::for($service, $service->tr('title').' — '.__('site.services.seo_suffix'), $service->tr('description'),
                $service->photo ? url($service->photo) : null, route('site.service', $service->slug)),
        ]);
    }
}
