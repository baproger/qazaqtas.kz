<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\SeoAiService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO карточек товара: автозаполнение при создании, ручные поля на двух
 * языках, генерация по кнопке (шаблон без ключа ИИ) и выдача на витрину.
 */
class SeoProductTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('admin');

        return $u;
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'Плитка «Волна» 300×300×60', 'slug' => 'plitka-volna',
            'unit' => 'м²', 'price' => 8900, 'is_active' => true, 'is_service' => false,
            'specs' => ['size' => '300 × 300 × 60 мм'],
        ]);
    }

    public function test_template_generator_builds_both_locales(): void
    {
        $g = app(SeoAiService::class)->template($this->product()->load(['translations', 'category']));

        $this->assertStringContainsString('QAZAQ TAS', $g['ru']['title']);
        $this->assertStringContainsString('8 900', $g['ru']['description']);
        $this->assertLessThanOrEqual(72, mb_strlen($g['ru']['title']));
        $this->assertLessThanOrEqual(162, mb_strlen($g['ru']['description']));
        $this->assertStringContainsString('QAZAQ TAS', $g['kk']['title']);
        $this->assertStringContainsString('мәрмәр композиті', $g['kk']['description']);
        $this->assertNotSame('', $g['kk']['keywords']);
    }

    public function test_creating_a_product_autofills_seo(): void
    {
        $this->actingAs($this->admin())->post(route('catalog.store'), [
            'name' => 'Бордюр «Тест»', 'unit' => 'п.м.', 'price' => 4000,
            'is_active' => true, 'is_service' => false,
        ])->assertRedirect();

        $product = Product::where('name', 'Бордюр «Тест»')->firstOrFail();
        $this->assertNotNull($product->seoMeta);
        $this->assertStringContainsString('Бордюр «Тест»', $product->seoMeta->title);
        $this->assertNotEmpty($product->seoMeta->title_kk);
        $this->assertNotEmpty($product->seoMeta->keywords);
    }

    public function test_generate_endpoint_falls_back_to_template_without_ai_key(): void
    {
        config(['services.anthropic.key' => null]);

        $this->actingAs($this->admin())
            ->postJson(route('catalog.seo.generate', $this->product()))
            ->assertOk()
            ->assertJsonPath('source', 'template')
            ->assertJsonStructure(['ru' => ['title', 'description', 'keywords'], 'kk' => ['title', 'description', 'keywords']]);
    }

    public function test_saved_seo_wins_on_storefront_with_locale_fallback(): void
    {
        $product = $this->product();
        $this->actingAs($this->admin())->postJson(route('catalog.seo.save', $product), [
            'title' => 'Ручной заголовок RU', 'description' => 'Ручное описание RU', 'keywords' => 'плитка, казахстан',
            'title_kk' => 'Қолмен жазылған KK', 'description_kk' => '', 'keywords_kk' => '',
        ])->assertOk();

        // Русская страница: ручные ru-поля + canonical без параметров.
        $this->get('/ru/katalog/' . $product->slug)->assertInertia(fn ($p) => $p
            ->where('seo.title', 'Ручной заголовок RU')
            ->where('seo.keywords', 'плитка, казахстан')
            ->where('seo.canonical', url('/ru/katalog/' . $product->slug)));

        // Казахская: свой title, а пустое описание добирается из ru.
        $this->get('/katalog/' . $product->slug)->assertInertia(fn ($p) => $p
            ->where('seo.title', 'Қолмен жазылған KK')
            ->where('seo.description', 'Ручное описание RU'));
    }

    public function test_translate_falls_back_to_dictionary_template_without_ai_key(): void
    {
        config(['services.anthropic.key' => null]);

        $response = $this->actingAs($this->admin())
            ->postJson(route('catalog.translate', $this->product()), [
                'name' => 'Плитка «Квадрат» 300×300×60',
                'short_description' => '300 × 300 × 60 мм · мраморный композит',
                'specs' => ['material' => 'мраморный композит'],
                'colors' => [['name' => 'Мрамор белый', 'hex' => '#E8E6E1']],
            ])->assertOk();

        $response->assertJsonPath('source', 'template')
            ->assertJsonPath('kk.short_description', '300 × 300 × 60 мм · мәрмәр композиті')
            ->assertJsonPath('kk.specs.material', 'мәрмәр композиті')
            ->assertJsonPath('kk.colors.0.name', 'Ақ мәрмәр')
            ->assertJsonPath('kk.colors.0.hex', '#E8E6E1')
            ->assertJsonPath('ru.name', 'Плитка «Квадрат» 300×300×60');

        $this->assertStringContainsString('QAZAQ TAS зауыты', $response->json('kk.description'));
    }

    public function test_seo_endpoints_require_catalog_rights(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $worker = User::factory()->create();
        $worker->assignRole('foreman');

        $this->actingAs($worker)
            ->postJson(route('catalog.seo.save', $this->product()), ['title' => 'x'])
            ->assertForbidden();
    }
}
