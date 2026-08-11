<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SiteContent;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Настройки → Сайт: контакты, филиалы, тарифы и FAQ правятся из ERP. */
class SiteSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_admin_edits_site_contacts_and_they_reach_the_storefront(): void
    {
        $this->actingAs($this->admin())->put(route('siteSettings.update'), [
            'phone' => '+7 700 111 22 33',
            'whatsapp' => '+7 771 610 77 70',
            'email' => 'sales@qazaqtas.kz',
            'hours' => 'Пн–Пт, 10:00–19:00',
            'branches' => [['city' => 'Шымкент', 'address' => 'ул. Промышленная, 1', 'role' => 'Головное', 'phone' => '+7 700 111 22 33']],
            'delivery' => [['city' => 'Шымкент', 'base' => 15000, 'per_km' => 250, 'free_from' => 1500000]],
            'faq' => [['q' => 'Есть ли гарантия?', 'a' => 'Да, 5 лет.']],
        ])->assertRedirect();

        $contacts = SiteContent::contacts();
        $this->assertSame('+7 700 111 22 33', $contacts['phone']);
        // В ссылку wa.me уходят только цифры.
        $this->assertSame('77716107770', $contacts['whatsapp']);

        $this->get(route('site.contacts'))
            ->assertInertia(fn ($p) => $p
                ->where('site.contacts.phone', '+7 700 111 22 33')
                ->has('site.branches', 1)
                ->where('faq.0.q', 'Есть ли гарантия?'));
    }

    public function test_phone_is_required(): void
    {
        $this->actingAs($this->admin())->put(route('siteSettings.update'), ['whatsapp' => '+77771234567'])
            ->assertSessionHasErrors('phone');
    }

    public function test_page_is_closed_for_managers(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->actingAs($manager)->get(route('siteSettings.index'))->assertForbidden();
    }
}
