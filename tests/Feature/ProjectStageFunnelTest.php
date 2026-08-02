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
        $qt = Company::firstOrCreate(['code' => 'QT'], ['name' => 'QT', 'is_active' => true]);
        $alt = Company::firstOrCreate(['code' => 'ALT'], ['name' => 'ALT', 'is_active' => true]);

        // Легаси «общие» этапы (company_id = null) с теми же названиями, что и у фирмы.
        foreach (['Кесу', 'Упаковка'] as $i => $name) {
            ProjectStage::create(['name' => $name, 'order' => $i + 1, 'is_active' => true]);
            ProjectStage::create(['name' => $name, 'order' => $i + 1, 'is_active' => true, 'company_id' => $qt->id]);
        }

        // У QT есть СВОИ этапы → только они, без легаси-дублей (Кесу+Кесу).
        $names = ProjectStage::funnel($qt->id)->pluck('name');
        $this->assertCount(2, $names);
        $this->assertSame($names->count(), $names->unique()->count());

        // У ALT своих нет → фолбэк на общие (легаси), а не пусто.
        $this->assertCount(2, ProjectStage::funnel($alt->id));

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

        $qt = Company::firstOrCreate(['code' => 'QT'], ['name' => 'QT', 'is_active' => true]);
        $alt = Company::firstOrCreate(['code' => 'ALT'], ['name' => 'ALT', 'is_active' => true]);
        foreach ([['Кесу', 1], ['Отправка', 2]] as [$name, $order]) {
            ProjectStage::create(['name' => $name, 'order' => $order, 'is_active' => true, 'company_id' => $qt->id]);
            ProjectStage::create(['name' => $name, 'order' => $order, 'is_active' => true, 'company_id' => $alt->id]);
        }

        $deal = \App\Models\Deal::create([
            'number' => 'QT-T-1', 'name' => 'ТОО', 'company_name' => 'ТОО', 'client_name' => 'товар',
            'budget' => 100, 'status' => 'active', 'company_id' => $qt->id,
            'deal_stage_id' => \App\Models\DealStage::orderBy('order')->first()->id,
        ]);
        $project = \App\Models\Project::create([
            'number' => 'PRJ-T-1', 'name' => 'ТОО', 'deal_id' => $deal->id, 'status' => 'active',
            'project_stage_id' => ProjectStage::where('company_id', $qt->id)->first()->id,
        ]);

        $this->actingAs($admin)->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('stages', 2)); // только этапы QT, без дублей ALT
    }
}
