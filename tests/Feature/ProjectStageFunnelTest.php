<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ProjectStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectStageFunnelTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_stages_do_not_duplicate_with_legacy_common_ones(): void
    {
        $baia = Company::firstOrCreate(['code' => 'BAIA'], ['name' => 'BAIA', 'is_active' => true]);
        $asu = Company::firstOrCreate(['code' => 'ASU'], ['name' => 'ASU', 'is_active' => true]);

        // Легаси «общие» этапы (company_id = null) с теми же названиями, что и у фирмы.
        foreach (['Кесу', 'Упаковка'] as $i => $name) {
            ProjectStage::create(['name' => $name, 'order' => $i + 1, 'is_active' => true]);
            ProjectStage::create(['name' => $name, 'order' => $i + 1, 'is_active' => true, 'company_id' => $baia->id]);
        }

        // У BAIA есть СВОИ этапы → только они, без легаси-дублей (Кесу+Кесу).
        $names = ProjectStage::funnel($baia->id)->pluck('name');
        $this->assertCount(2, $names);
        $this->assertSame($names->count(), $names->unique()->count());

        // У ASU своих нет → фолбэк на общие (легаси), а не пусто.
        $this->assertCount(2, ProjectStage::funnel($asu->id));

        // Без компании («Все») — как раньше, всё вместе.
        $this->assertCount(4, ProjectStage::funnel(null));
    }

    public function test_project_card_shows_only_its_company_stages(): void
    {
        // Регрессия: deal подгружался без company_id → фильтр воронки получал
        // null и степпер заказа показывал обе фирмы (Кесу+Кесу…).
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        $this->seed(\Database\Seeders\StageSeeder::class);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        $baia = Company::firstOrCreate(['code' => 'BAIA'], ['name' => 'BAIA', 'is_active' => true]);
        $asu = Company::firstOrCreate(['code' => 'ASU'], ['name' => 'ASU', 'is_active' => true]);
        foreach ([['Кесу', 1], ['Отправка', 2]] as [$name, $order]) {
            ProjectStage::create(['name' => $name, 'order' => $order, 'is_active' => true, 'company_id' => $baia->id]);
            ProjectStage::create(['name' => $name, 'order' => $order, 'is_active' => true, 'company_id' => $asu->id]);
        }

        $deal = \App\Models\Deal::create([
            'number' => 'BAIA-T-1', 'name' => 'ТОО', 'company_name' => 'ТОО', 'client_name' => 'товар',
            'budget' => 100, 'status' => 'active', 'company_id' => $baia->id,
            'deal_stage_id' => \App\Models\DealStage::orderBy('order')->first()->id,
        ]);
        $project = \App\Models\Project::create([
            'number' => 'PRJ-T-1', 'name' => 'ТОО', 'deal_id' => $deal->id, 'status' => 'active',
            'project_stage_id' => ProjectStage::where('company_id', $baia->id)->first()->id,
        ]);

        $this->actingAs($admin)->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('stages', 2)); // только этапы BAIA, без дублей ASU
    }
}
