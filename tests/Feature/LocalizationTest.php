<?php

namespace Tests\Feature;

use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $u = User::factory()->create(['language' => 'ru']);
        $u->assignRole('admin');
        return $u;
    }

    public function test_locale_switch_persists_to_user(): void
    {
        $u = $this->admin();

        $this->actingAs($u)->patch(route('locale.update'), ['locale' => 'kk'])->assertRedirect();
        $this->assertEquals('kk', $u->fresh()->language);
    }

    public function test_stage_name_is_translated(): void
    {
        $this->admin();

        app()->setLocale('kk');
        $stage = DealStage::with('translations')->orderBy('order')->first();
        $this->assertEquals('Жаңа', $stage->translatedName());

        app()->setLocale('ru');
        $this->assertEquals('Новая', $stage->translatedName());
    }

    public function test_deals_index_renders_under_kk(): void
    {
        $u = $this->admin();
        $u->update(['language' => 'kk']);
        $this->actingAs($u)->get(route('deals.index'))->assertOk();
    }
}
