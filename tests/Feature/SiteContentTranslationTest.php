<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\SiteContent;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Тексты сайта из настроек: перевод лежит в той же строке под суффиксом
 * языка, а цифры и телефоны остаются общими.
 */
class SiteContentTranslationTest extends TestCase
{
    use RefreshDatabase;

    public function test_row_shows_the_language_version(): void
    {
        Setting::set('site_faq', [
            ['q' => 'Какой минимальный заказ?', 'q_kk' => 'Ең аз тапсырыс қандай?', 'a' => 'От 10 м².', 'a_kk' => '10 м²-ден.'],
        ]);

        $this->get('/kontakty')->assertInertia(fn ($page) => $page
            ->where('faq.0.q', 'Ең аз тапсырыс қандай?')
            ->where('faq.0.a', '10 м²-ден.'));

        $this->get('/ru/kontakty')->assertInertia(fn ($page) => $page
            ->where('faq.0.q', 'Какой минимальный заказ?'));
    }

    public function test_untranslated_field_falls_back_to_the_base_value(): void
    {
        Setting::set('site_faq', [
            ['q' => 'Даёте ли гарантию?', 'q_kk' => 'Кепілдік бересіздер ме?', 'a' => 'Пять лет.'],
        ]);

        $this->get('/kontakty')->assertInertia(fn ($page) => $page
            ->where('faq.0.q', 'Кепілдік бересіздер ме?')
            ->where('faq.0.a', 'Пять лет.'));
    }

    /**
     * Суффиксы — внутренняя механика хранения. Наружу они не выходят, иначе
     * всплыли бы в вёрстке и в составе заказа.
     */
    public function test_language_suffixes_do_not_leak_to_the_site(): void
    {
        Setting::set('site_branches', [
            ['city' => 'Шымкент', 'role' => 'Головное производство', 'role_kk' => 'Бас өндіріс', 'phone' => '+7 707 372 22 22'],
        ]);

        $this->get('/kontakty')->assertInertia(fn ($page) => $page
            ->where('site.branches.0.role', 'Бас өндіріс')
            ->where('site.branches.0.phone', '+7 707 372 22 22')
            ->missing('site.branches.0.role_kk'));
    }

    public function test_delivery_keeps_shared_numbers_across_languages(): void
    {
        Setting::set('site_delivery', [
            ['city' => 'Другой город', 'city_kk' => 'Басқа қала', 'base' => 25000, 'per_km' => 320, 'free_from' => 2500000],
        ]);

        $this->get('/korzina')->assertInertia(fn ($page) => $page
            ->where('delivery.0.city', 'Басқа қала')
            ->where('delivery.0.base', 25000));

        $this->get('/ru/korzina')->assertInertia(fn ($page) => $page
            ->where('delivery.0.city', 'Другой город')
            ->where('delivery.0.base', 25000));
    }

    public function test_admin_form_receives_rows_with_their_suffixes(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Setting::set('site_faq', [['q' => 'Вопрос', 'q_kk' => 'Сұрақ', 'a' => 'Ответ']]);

        // Форма правит оба языка, поэтому получает строку как есть.
        $this->actingAs($admin)->get(route('siteSettings.index'))
            ->assertInertia(fn ($page) => $page
                ->where('site.faq.0.q', 'Вопрос')
                ->where('site.faq.0.q_kk', 'Сұрақ'));
    }

    /**
     * Форма отправляет строку целиком, вместе с языковыми суффиксами и
     * координатами. Любое незаявленное поле validate() молча выбросил бы —
     * и перевод, сохранённый вчера, исчез бы при первой же правке телефона.
     */
    public function test_saving_settings_keeps_both_languages_and_extra_fields(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->put(route('siteSettings.update'), [
            'phone' => '+7 707 372 22 22',
            'whatsapp' => '77716107770',
            'hours' => 'Пн–Сб, 09:00–18:00',
            'hours_kk' => 'Дс–Сб, 09:00–18:00',
            'branches' => [[
                'city' => 'Шымкент',
                'role' => 'Головное производство',
                'role_kk' => 'Бас өндіріс',
                'address' => 'ул. Промышленная, 1',
                'address_kk' => 'Промышленная көшесі, 1',
                'phone' => '+7 707 372 22 22',
                'coords' => '42.3417, 69.5901',
            ]],
            'delivery' => [[
                'city' => 'Шымкент', 'city_kk' => 'Шымкент',
                'base' => 15000, 'per_km' => 250, 'free_from' => 1500000,
            ]],
            'faq' => [['q' => 'Вопрос', 'q_kk' => 'Сұрақ', 'a' => 'Ответ', 'a_kk' => 'Жауап']],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $branch = Setting::get('site_branches')[0];
        $this->assertSame('Бас өндіріс', $branch['role_kk']);
        $this->assertSame('42.3417, 69.5901', $branch['coords']);
        $this->assertSame('Сұрақ', Setting::get('site_faq')[0]['q_kk']);

        // И на витрине это уже разложено по языкам.
        app()->setLocale('kk');
        $this->assertSame('Бас өндіріс', SiteContent::branches()[0]['role']);
        $this->assertSame('Дс–Сб, 09:00–18:00', SiteContent::contacts()['hours']);

        app()->setLocale('ru');
        $this->assertSame('Головное производство', SiteContent::branches()[0]['role']);
        $this->assertSame('Пн–Сб, 09:00–18:00', SiteContent::contacts()['hours']);
    }

    /**
     * КП уходит клиенту в руки: документ собирается целиком на языке
     * страницы, с которой его скачали, — включая префикс номера в имени файла.
     */
    public function test_quotation_pdf_follows_the_page_language(): void
    {
        $product = \App\Models\Product::create([
            'name' => 'Плитка Квадрат', 'slug' => 'plitka-kvadrat',
            'unit' => 'м²', 'price' => 8900, 'is_active' => true, 'in_stock' => true,
        ]);

        $this->post('/korzina/'.$product->slug, ['quantity' => 10])->assertRedirect();

        $kk = $this->get('/kp');
        $kk->assertOk();
        // Кириллическое имя уходит RFC-5987-хвостом: filename*=utf-8''%D0%9A...
        $this->assertStringContainsString(rawurlencode('КҰ'), $kk->headers->get('content-disposition'));

        $ru = $this->get('/ru/kp');
        $ru->assertOk();
        $this->assertStringContainsString(rawurlencode('КП'), $ru->headers->get('content-disposition'));
    }

    /** Пустая корзина возвращает на корзину ТОГО ЖЕ языка, не основного. */
    public function test_empty_cart_quotation_redirects_to_the_same_language(): void
    {
        $this->get('/kp')->assertRedirect('/korzina');
        $this->get('/ru/kp')->assertRedirect('/ru/korzina');
    }

    public function test_defaults_ship_with_both_languages(): void
    {
        app()->setLocale('kk');
        $faq = SiteContent::faq();

        $this->assertNotEmpty($faq);
        $this->assertStringContainsString('Мәрмәр композиті', $faq[0]['q']);
    }
}
