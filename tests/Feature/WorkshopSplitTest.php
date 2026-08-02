<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\ProjectStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * У BAIA два цеха («Металл цех» / «Ағаш цех») со своими этапами: при отправке
 * в цех нужен выбор, заказ живёт в воронке своего цеха. У компании с одним
 * цехом (ASU) всё как раньше — без выбора.
 */
class WorkshopSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    private function baiaWithTwoWorkshops(): array
    {
        $company = Company::firstOrCreate(['code' => 'BAIA'], ['name' => 'BAIA']);
        foreach ([['Кесу М', 1], ['Жинау М', 2]] as [$n, $o]) {
            ProjectStage::create(['company_id' => $company->id, 'workshop' => 'Металл цех', 'name' => $n, 'order' => $o, 'type' => 'project', 'is_active' => true, 'is_completed' => $o === 2]);
        }
        foreach ([['Кесу А', 1], ['Жинау А', 2]] as [$n, $o]) {
            ProjectStage::create(['company_id' => $company->id, 'workshop' => 'Ағаш цех', 'name' => $n, 'order' => $o, 'type' => 'project', 'is_active' => true, 'is_completed' => $o === 2]);
        }

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $deal = Deal::create(['number' => 'BAIA-001', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 100000, 'status' => 'active', 'company_id' => $company->id, 'deal_stage_id' => DealStage::orderBy('order')->first()->id]);

        return [$admin, $deal, $company];
    }

    public function test_two_workshops_require_choice_and_use_own_funnel(): void
    {
        [$admin, $deal] = $this->baiaWithTwoWorkshops();

        // Без выбора цеха — ошибка, заказ не создан.
        $this->actingAs($admin)->post(route('deals.toWorkshop', $deal->id))->assertSessionHas('error');
        $this->assertNull($deal->fresh()->project);

        // С выбором «Ағаш цех» — заказ на ПЕРВОМ этапе воронки этого цеха.
        $this->actingAs($admin)->post(route('deals.toWorkshop', $deal->id), ['workshop' => 'Ағаш цех'])->assertSessionHas('success');
        $project = $deal->fresh()->project;
        $this->assertSame('Ағаш цех', $project->workshop);
        $this->assertSame('Кесу А', $project->stage->name);

        // «Далее» двигает по воронке СВОЕГО цеха (Кесу А → Жинау А, не в металл).
        $this->actingAs($admin)->patch(route('projects.advance', $project->id));
        $this->assertSame('Жинау А', $project->fresh()->stage->name);
    }

    public function test_single_workshop_company_needs_no_choice(): void
    {
        $company = Company::firstOrCreate(['code' => 'ASU'], ['name' => 'ASU']);
        ProjectStage::create(['company_id' => $company->id, 'name' => 'Пошив', 'order' => 1, 'type' => 'project', 'is_active' => true]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $deal = Deal::create(['number' => 'ASU-001', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 100, 'status' => 'active', 'company_id' => $company->id, 'deal_stage_id' => DealStage::orderBy('order')->first()->id]);

        $this->actingAs($admin)->post(route('deals.toWorkshop', $deal->id))->assertSessionHas('success');
        $this->assertSame('Пошив', $deal->fresh()->project->stage->name);
        $this->assertNull($deal->fresh()->project->workshop);
    }
}
