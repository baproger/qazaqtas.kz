<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Язык витрины задаёт адрес, язык ERP — карточка сотрудника.
 */
class SiteLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_bare_address_opens_in_kazakh(): void
    {
        $this->get('/')->assertOk();

        $this->assertSame('kk', app()->getLocale());
    }

    public function test_ru_prefix_opens_in_russian(): void
    {
        $this->get('/ru/katalog')->assertOk();

        $this->assertSame('ru', app()->getLocale());
    }

    /**
     * Сохранённый выбор не должен перебивать присланную ссылку: иначе
     * посетитель, однажды переключившийся на русский, открывал бы казахский
     * адрес по-русски, а поисковик получал бы разный текст по одному URL.
     */
    public function test_saved_choice_does_not_override_the_address(): void
    {
        $this->withSession(['locale' => 'ru'])->get('/katalog')->assertOk();

        $this->assertSame('kk', app()->getLocale());
    }

    public function test_primary_language_prefix_redirects_to_canonical_address(): void
    {
        $this->get('/kk/katalog')->assertRedirect('/katalog');
    }

    public function test_switching_default_language_moves_the_prefix(): void
    {
        Setting::set('default_locale', 'ru');

        // Русский стал основным — теперь без префикса открывается он,
        // а казахский переезжает под /kk/.
        $this->get('/katalog')->assertOk();
        $this->assertSame('ru', app()->getLocale());

        $this->get('/ru/katalog')->assertRedirect('/katalog');

        $this->get('/kk/katalog')->assertOk();
        $this->assertSame('kk', app()->getLocale());
    }

    public function test_erp_takes_language_from_the_employee_card(): void
    {
        $user = User::factory()->create(['language' => 'ru']);

        $this->actingAs($user)->get(route('profile.edit'))->assertOk();

        $this->assertSame('ru', app()->getLocale());
    }

    public function test_erp_falls_back_to_the_default_language(): void
    {
        $user = User::factory()->create(['language' => null]);

        $this->actingAs($user)->get(route('profile.edit'))->assertOk();

        $this->assertSame(Locales::default(), app()->getLocale());
    }

    public function test_page_lists_every_language_version(): void
    {
        $this->get('/katalog')
            ->assertSee('hreflang="kk"', false)
            ->assertSee('hreflang="ru"', false)
            ->assertSee('/ru/katalog', false);
    }
}
