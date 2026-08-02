<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('admin');
        return $u;
    }

    public function test_settings_persist_and_cache(): void
    {
        $u = $this->admin();

        $this->actingAs($u)->put(route('settings.update'), [
            'company_name' => 'BAIA', 'currency' => '$', 'auto_create_project' => false, 'default_locale' => 'kk', 'bonus_percent' => 10, 'tax_percent' => 3,
        ])->assertRedirect();

        $this->assertFalse(Setting::get('auto_create_project'));
        $this->assertEquals('$', Setting::get('currency'));
    }

    public function test_disabling_auto_project_prevents_creation(): void
    {
        $u = $this->admin();
        Setting::set('auto_create_project', false);

        $deal = Deal::create([
            'number' => 'BAIA-S-1', 'name' => 'D', 'budget' => 1, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);
        $won = DealStage::where('is_won', true)->first();

        $this->actingAs($u)->patch(route('deals.stage', $deal), ['deal_stage_id' => $won->id])->assertRedirect();

        $this->assertEquals('active', $deal->fresh()->status);
        $this->assertEquals(0, Project::count()); // no auto project
    }
}
