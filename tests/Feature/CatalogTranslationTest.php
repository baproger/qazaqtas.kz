<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SiteProject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Карточка каталога на двух языках: базовые колонки — запасной вариант,
 * таблица переводов перекрывает их для своего языка.
 */
class CatalogTranslationTest extends TestCase
{
    use RefreshDatabase;

    private function category(): ProductCategory
    {
        return ProductCategory::create(['name' => 'Тротуарная плитка', 'slug' => 'trotuarnaya-plitka']);
    }

    private function product(ProductCategory $category): Product
    {
        return Product::create([
            'category_id' => $category->id,
            'name' => 'Плитка Квадрат',
            'slug' => 'plitka-kvadrat',
            'unit' => 'м²',
            'price' => 7500,
            'short_description' => 'Классический квадрат',
            'specs' => ['surface' => 'шлифованная'],
            'colors' => [['name' => 'Мрамор белый', 'hex' => '#E8E6E1']],
            'is_active' => true,
        ]);
    }

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_translation_replaces_the_base_value(): void
    {
        $product = $this->product($this->category());
        $product->saveTranslations(['kk' => ['name' => 'Шаршы плитка']]);

        $this->assertSame('Шаршы плитка', $product->fresh()->tr('name', 'kk'));
        $this->assertSame('Плитка Квадрат', $product->fresh()->tr('name', 'ru'));
    }

    /**
     * Незаполненное поле — не пустой текст на витрине, а «как в карточке».
     * Иначе полупереведённая позиция показывала бы покупателю дыру.
     */
    public function test_empty_field_falls_back_to_the_base_value(): void
    {
        $product = $this->product($this->category());
        $product->saveTranslations(['kk' => ['name' => 'Шаршы плитка', 'short_description' => '   ']]);

        $fresh = $product->fresh();
        $this->assertSame('Шаршы плитка', $fresh->tr('name', 'kk'));
        $this->assertSame('Классический квадрат', $fresh->tr('short_description', 'kk'));
    }

    public function test_fully_empty_language_leaves_no_row_behind(): void
    {
        $product = $this->product($this->category());

        $product->saveTranslations(['kk' => ['name' => 'Шаршы плитка']]);
        $this->assertSame(1, $product->translations()->count());

        $product->saveTranslations(['kk' => ['name' => '']]);
        $this->assertSame(0, $product->translations()->count());
    }

    public function test_site_shows_the_language_of_the_address(): void
    {
        $category = $this->category();
        $category->saveTranslations(['kk' => ['name' => 'Тротуар плиткасы']]);

        $product = $this->product($category);
        $product->saveTranslations(['kk' => ['name' => 'Шаршы плитка']]);

        $this->get('/katalog')->assertInertia(fn ($page) => $page
            ->where('products.data.0.name', 'Шаршы плитка')
            ->where('categories.0.name', 'Тротуар плиткасы'));

        $this->get('/ru/katalog')->assertInertia(fn ($page) => $page
            ->where('products.data.0.name', 'Плитка Квадрат')
            ->where('categories.0.name', 'Тротуарная плитка'));
    }

    public function test_product_page_and_seo_follow_the_language(): void
    {
        $product = $this->product($this->category());
        $product->saveTranslations(['kk' => ['name' => 'Шаршы плитка', 'short_description' => 'Классикалық шаршы']]);

        $this->get('/katalog/plitka-kvadrat')->assertInertia(fn ($page) => $page
            ->where('product.name', 'Шаршы плитка')
            ->where('product.short_description', 'Классикалық шаршы')
            ->where('seo.description', 'Классикалық шаршы'));

        $this->get('/ru/katalog/plitka-kvadrat')->assertInertia(fn ($page) => $page
            ->where('product.name', 'Плитка Квадрат')
            ->where('seo.description', 'Классический квадрат'));
    }

    public function test_search_finds_a_product_by_its_translated_name(): void
    {
        $product = $this->product($this->category());
        $product->saveTranslations(['kk' => ['name' => 'Шаршы плитка']]);

        $this->get('/katalog?search=Шаршы')->assertInertia(fn ($page) => $page
            ->where('products.total', 1)
            ->where('products.data.0.name', 'Шаршы плитка'));
    }

    public function test_erp_form_gets_base_values_and_translations_apart(): void
    {
        $product = $this->product($this->category());
        $product->saveTranslations(['kk' => ['name' => 'Шаршы плитка']]);

        // В форме — базовое название: иначе правка сохранила бы перевод
        // обратно в базовую колонку и язык-оригинал потерялся бы.
        $this->actingAs($this->admin())->get(route('catalog.index'))
            ->assertInertia(fn ($page) => $page
                ->where('products.data.0.name', 'Плитка Квадрат')
                ->where('products.data.0.translations_map.kk.name', 'Шаршы плитка'));
    }

    public function test_admin_saves_translations_from_the_card(): void
    {
        $product = $this->product($this->category());

        $this->actingAs($this->admin())->put(route('catalog.update', $product->id), [
            'category_id' => $product->category_id,
            'name' => 'Плитка Квадрат',
            'unit' => 'м²',
            'price' => 7500,
            'translations' => ['kk' => ['name' => 'Шаршы плитка']],
        ])->assertRedirect();

        $this->assertSame('Шаршы плитка', $product->fresh()->tr('name', 'kk'));
        $this->assertSame('Плитка Квадрат', $product->fresh()->name);
    }

    public function test_site_object_follows_the_language(): void
    {
        $project = SiteProject::create([
            'title' => 'Двор ЖК «Астана»', 'city' => 'Шымкент', 'is_active' => true,
        ]);
        $project->saveTranslations(['kk' => ['title' => '«Астана» ТК ауласы', 'city' => 'Шымкент']]);

        $this->get('/proekty')->assertInertia(fn ($page) => $page
            ->where('projects.0.title', '«Астана» ТК ауласы'));

        $this->get('/ru/proekty')->assertInertia(fn ($page) => $page
            ->where('projects.0.title', 'Двор ЖК «Астана»'));
    }
}
