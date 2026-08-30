<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * sitemap.xml и robots.txt. Карта собирается из публичного: статические
 * страницы, активный каталог, объекты, одобренные услуги. Кеш на час.
 */
class SeoController extends Controller
{
    public function sitemap(): Response
    {
        $xml = Cache::remember('seo:sitemap', 3600, function () {
            $urls = collect([
                ['loc' => route('site.home'), 'priority' => '1.0'],
                ['loc' => route('site.catalog'), 'priority' => '0.9'],
                ['loc' => route('site.services'), 'priority' => '0.8'],
                ['loc' => route('site.about'), 'priority' => '0.6'],
                ['loc' => route('site.projects'), 'priority' => '0.6'],
                ['loc' => route('site.contacts'), 'priority' => '0.5'],
            ]);

            $urls = $urls
                ->concat(Product::where('is_active', true)->whereNotNull('slug')->get(['slug', 'updated_at'])
                    ->map(fn ($p) => ['loc' => route('site.product', $p->slug), 'lastmod' => $p->updated_at?->toDateString(), 'priority' => '0.7']))
                ->concat(Service::approved()->get(['slug', 'updated_at'])
                    ->map(fn ($s) => ['loc' => route('site.service', $s->slug), 'lastmod' => $s->updated_at?->toDateString(), 'priority' => '0.6']));

            $items = $urls->map(function ($u) {
                $lastmod = isset($u['lastmod']) ? "<lastmod>{$u['lastmod']}</lastmod>" : '';

                return '<url><loc>'.e($u['loc'])."</loc>{$lastmod}<priority>{$u['priority']}</priority></url>";
            })->implode('');

            return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$items.'</urlset>';
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Disallow: /login',
            'Disallow: /korzina',
            'Disallow: /oformlenie',
            'Disallow: /kp',
            'Disallow: /storage/documents',
            '',
            'Sitemap: '.route('seo.sitemap'),
        ];

        return response(implode("\n", $lines), 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
