<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Фильтр каталога и слой снимков брусчатки на главной. */
class CategoryNavTest extends TestCase
{
    use RefreshDatabase;

    private function category(string $slug, string $name): ProductCategory
    {
        return ProductCategory::create(['name' => $name, 'slug' => $slug, 'is_active' => true, 'order' => 0]);
    }

    private function product(ProductCategory $category, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'category_id' => $category->id,
            'name' => 'Плитка «Квадрат»',
            'slug' => 'plitka-kvadrat',
            'unit' => 'м²',
            'price' => 8900,
            'is_active' => true,
            'order' => 0,
        ], $attributes));
    }

    public function test_catalog_filters_by_category_through_eloquent_bindings(): void
    {
        $tiles = $this->category('trotuarnaya-plitka', 'Тротуарная плитка');
        $curbs = $this->category('bordyury', 'Бордюры');
        $this->product($tiles);
        $this->product($curbs, ['name' => 'Бордюр дорожный', 'slug' => 'bordyur-dorozhnyi']);

        $this->get(route('site.catalog', ['category' => 'bordyury']))
            ->assertInertia(fn ($p) => $p
                ->where('filters.category', 'bordyury')
                ->has('products.data', 1)
                ->where('products.data.0.name', 'Бордюр дорожный'));
    }

    public function test_unknown_category_does_not_break_the_page(): void
    {
        $this->category('trotuarnaya-plitka', 'Тротуарная плитка');

        // Значение подставляется в запрос как связанный параметр, а не в текст SQL.
        $this->get(route('site.catalog', ['category' => "' OR 1=1 --"]))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('products.data', 0));
    }

    public function test_home_passes_paving_photos_for_the_depth_layer(): void
    {
        $tiles = $this->category('trotuarnaya-plitka', 'Тротуарная плитка');
        $this->product($tiles, ['images' => [
            ['path' => '/storage/catalog/1/a.jpg', 'thumb' => '/storage/catalog/1/a-thumb.jpg', 'alt' => 'Брусчатка'],
        ]]);

        $this->get(route('site.home'))
            ->assertInertia(fn ($p) => $p
                ->has('paving.0.images', 1)
                ->where('paving.0.images.0.thumb', '/storage/catalog/1/a-thumb.jpg')
                ->where('paving.0.images.0.alt', 'Брусчатка'));
    }

    public function test_paving_without_photos_yields_an_empty_layer(): void
    {
        $tiles = $this->category('trotuarnaya-plitka', 'Тротуарная плитка');
        $this->product($tiles);

        $this->get(route('site.home'))
            ->assertInertia(fn ($p) => $p->has('paving.0.images', 0));
    }
}
