<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Notifications\ServiceModerated;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\ServiceCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Модуль услуг: кабинет партнёра, модерация, публичный каталог, безопасность. */
class ServicesModuleTest extends TestCase
{
    use RefreshDatabase;

    private ServiceCategory $cat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(ServiceCategorySeeder::class);
        $this->cat = ServiceCategory::firstOrFail();
        Storage::fake('public');
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'title' => 'Укладка брусчатки под ключ', 'category_id' => $this->cat->id,
            'description' => 'Полный цикл: подготовка основания, укладка, виброплита. Опыт 10 лет.',
            'price' => 4500, 'contact_name' => 'Арман', 'contact_phone' => '+7 701 000 00 00', 'city' => 'Шымкент',
            'photo' => UploadedFile::fake()->image('foto.jpg', 1200, 800),
        ], $extra);
    }

    public function test_partner_creates_service_with_slug_and_webp_and_it_waits_moderation(): void
    {
        $partner = $this->user('partner');
        $this->actingAs($partner)->post(route('partner.services.store'), $this->payload([
            'description' => 'Полный цикл <script>alert(1)</script> укладки и уход.',
        ]))->assertSessionHasNoErrors();

        $s = Service::firstOrFail();
        $this->assertSame('pending', $s->status);
        $this->assertSame('ukladka-bruscatki-pod-kliuc', $s->slug); // ЧПУ с транслитерацией (Str::slug)
        $this->assertStringNotContainsString('<script>', $s->description); // XSS вычищен
        $this->assertNotNull($s->photo_webp); // авто-WebP
        // На витрине до одобрения не видна ни в списке, ни по прямому ЧПУ.
        $this->get(route('site.services'))->assertInertia(fn ($p) => $p->has('services.data', 0));
        $this->get(route('site.service', $s->slug))->assertNotFound();
    }

    public function test_partner_sees_only_own_services_and_idor_is_closed(): void
    {
        $a = $this->user('partner');
        $b = $this->user('partner');
        $this->actingAs($a)->post(route('partner.services.store'), $this->payload());
        $mine = Service::firstOrFail();

        $this->actingAs($b)->get(route('partner.services'))
            ->assertInertia(fn ($p) => $p->has('services', 0));
        $this->actingAs($b)->post(route('partner.services.update', $mine->id), array_merge($this->payload(['photo' => null]), ['_method' => 'PUT']))->assertForbidden();
        $this->actingAs($b)->delete(route('partner.services.destroy', $mine->id))->assertForbidden();
        // Менеджер в кабинет партнёра не попадает.
        $this->actingAs($this->user('manager'))->get(route('partner.services'))->assertForbidden();
    }

    public function test_moderation_approve_reject_and_public_catalog(): void
    {
        Notification::fake();
        $partner = $this->user('partner');
        $assistant = $this->user('assistant');
        $this->actingAs($partner)->post(route('partner.services.store'), $this->payload());
        $s = Service::firstOrFail();

        // Партнёру модерация закрыта.
        $this->actingAs($partner)->patch(route('moderation.services.approve', $s->id))->assertForbidden();

        $this->actingAs($assistant)->patch(route('moderation.services.approve', $s->id))->assertRedirect();
        $this->assertSame('approved', $s->fresh()->status);
        Notification::assertSentTo($partner, ServiceModerated::class);

        // Публичный каталог: видна, фильтр по категории и ЧПУ работают.
        $this->get(route('site.services', ['category' => $this->cat->slug]))
            ->assertInertia(fn ($p) => $p->component('Site/Services')->has('services.data', 1));
        $this->get(route('site.service', $s->slug))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Site/Service')
                ->where('service.title', $s->title)
                ->where('seo.canonical', route('site.service', $s->slug)));

        // Отклонение с причиной; правка возвращает на модерацию.
        $this->actingAs($assistant)->patch(route('moderation.services.reject', $s->id), ['reason' => 'Добавьте детали цены'])->assertRedirect();
        $this->assertSame('rejected', $s->fresh()->status);
        $this->actingAs($partner)->post(route('partner.services.update', $s->id), array_merge($this->payload(['photo' => null]), ['_method' => 'PUT']))->assertSessionHasNoErrors();
        $this->assertSame('pending', $s->fresh()->status);
    }

    public function test_upload_security_mime_checked_on_server(): void
    {
        $partner = $this->user('partner');
        // PHP-файл с расширением jpg: MIME проверяется по содержимому — отказ.
        $fake = UploadedFile::fake()->createWithContent('shell.jpg', '<?php echo 1;');
        $this->actingAs($partner)->post(route('partner.services.store'), $this->payload(['photo' => $fake]))
            ->assertSessionHasErrors('photo');
        $this->assertSame(0, Service::count());
    }

    public function test_store_is_rate_limited_five_per_hour(): void
    {
        $partner = $this->user('partner');
        for ($i = 1; $i <= 5; $i++) {
            $this->actingAs($partner)->post(route('partner.services.store'), $this->payload(['title' => "Услуга номер {$i} подлиннее"]))->assertRedirect();
        }
        $this->actingAs($partner)->post(route('partner.services.store'), $this->payload(['title' => 'Шестая услуга за час — спам']))->assertStatus(429);
    }

    public function test_sitemap_and_robots(): void
    {
        $partner = $this->user('partner');
        $this->actingAs($partner)->post(route('partner.services.store'), $this->payload());
        $s = Service::firstOrFail();

        // В карте — только одобренные.
        $this->get('/sitemap.xml')->assertOk()->assertDontSee(route('site.service', $s->slug));
        $s->update(['status' => 'approved']);
        cache()->forget('seo:sitemap');
        $this->get('/sitemap.xml')->assertOk()
            ->assertSee('<?xml', false)->assertSee(route('site.service', $s->slug), false)->assertSee(route('site.catalog'), false);
        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: '.route('seo.sitemap'))->assertSee('Disallow: /korzina');
    }

    /** Ассистент и админ публикуют услуги сразу и управляют категориями. */
    public function test_assistant_publishes_directly_and_manages_categories(): void
    {
        $assistant = $this->user('assistant');
        $this->actingAs($assistant)->post(route('partner.services.store'), $this->payload())->assertSessionHasNoErrors();
        $s = Service::firstOrFail();
        $this->assertSame('approved', $s->status);
        $this->get(route('site.service', $s->slug))->assertOk();

        // Категории: добавить, переименовать, скрыть, удалить (услуги остаются).
        $this->actingAs($assistant)->post(route('moderation.serviceCategories.store'), ['name' => 'Реставрация камня'])->assertRedirect();
        $cat = ServiceCategory::where('name', 'Реставрация камня')->firstOrFail();
        $this->assertSame('restavraciia-kamnia', $cat->slug);
        $this->actingAs($assistant)->put(route('moderation.serviceCategories.update', $cat), ['name' => 'Реставрация', 'is_active' => false])->assertRedirect();
        $this->assertFalse($cat->fresh()->is_active);

        $this->actingAs($assistant)->delete(route('moderation.serviceCategories.destroy', $this->cat))->assertRedirect();
        $this->assertNull($s->fresh()->category_id);
        $this->assertSame('approved', $s->fresh()->status, 'Удаление категории не трогает услугу.');

        // Партнёру категории недоступны.
        $partner = $this->user('partner');
        $this->actingAs($partner)->post(route('moderation.serviceCategories.store'), ['name' => 'Взлом'])->assertForbidden();
    }

    /** Переводы: витрина показывает текст языка страницы, пустой перевод падает на базовый. */
    public function test_service_and_category_translations_reach_the_site(): void
    {
        $assistant = $this->user('assistant');
        $this->actingAs($assistant)->post(route('partner.services.store'), $this->payload([
            'translations' => ['kk' => ['title' => 'Брусчатка төсеу «кілт тапсыру»', 'description' => 'Толық цикл: негіз дайындау, төсеу.'], 'ru' => ['title' => '', 'description' => '']],
        ]))->assertSessionHasNoErrors();
        $s = Service::firstOrFail();
        $this->assertSame('Брусчатка төсеу «кілт тапсыру»', $s->tr('title', 'kk'));

        $this->actingAs($assistant)->put(route('moderation.serviceCategories.update', $this->cat), ['name_kk' => 'Төсеу және монтаж'])->assertRedirect();

        // KZ (язык по умолчанию, без префикса): казахские тексты.
        $this->get(route('site.service', $s->slug))->assertOk()
            ->assertInertia(fn ($p) => $p->where('service.title', 'Брусчатка төсеу «кілт тапсыру»')
                ->where('service.category.name', 'Төсеу және монтаж'));
        // RU-версия: перевода нет — базовый русский текст.
        $this->get('/ru'.parse_url(route('site.service', $s->slug), PHP_URL_PATH))->assertOk()
            ->assertInertia(fn ($p) => $p->where('service.title', $s->title));
    }

    /** Фильтры каталога: город, цена «до», сортировка по цене. */
    public function test_public_filters_city_price_and_sort(): void
    {
        $assistant = $this->user('assistant');
        $mk = fn ($t, $price, $city) => $this->actingAs($assistant)->post(route('partner.services.store'), $this->payload([
            'title' => $t, 'price' => $price, 'city' => $city]));
        $mk('Укладка брусчатки премиум', 9000, 'Алматы');
        $mk('Доставка камня по городу', 3000, 'Шымкент');
        $mk('Проект двора под ключ здесь', null, 'Шымкент');

        $this->get(route('site.services', ['city' => 'Шымкент']))
            ->assertInertia(fn ($p) => $p->has('services.data', 2)->where('cities', fn ($c) => count($c) === 2));
        $this->get(route('site.services', ['price_max' => 5000, 'city' => 'Шымкент']))
            ->assertInertia(fn ($p) => $p->has('services.data', 2)); // 3000 + договорная
        $this->get(route('site.services', ['sort' => 'cheap']))
            ->assertInertia(fn ($p) => $p->where('services.data.0.price', 3000)
                ->where('services.data.2.price', null)); // договорная в конце
    }
}
