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
 * У QT два цеха («Шымкент» / «Алматы») со своими этапами: при отправке
 * в цех нужен выбор, заказ живёт в воронке своего цеха. У компании с одним
 * одним цехом выбор не показывается.
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

    private function companyWithTwoWorkshops(): array
    {
        $company = Company::firstOrCreate(['code' => 'QT'], ['name' => 'QT']);
        foreach ([['Формовка Ш', 1], ['Упаковка Ш', 2]] as [$n, $o]) {
            ProjectStage::create(['company_id' => $company->id, 'workshop' => 'Шымкент', 'name' => $n, 'order' => $o, 'type' => 'project', 'is_active' => true, 'is_completed' => $o === 2]);
        }
        foreach ([['Формовка А', 1], ['Упаковка А', 2]] as [$n, $o]) {
            ProjectStage::create(['company_id' => $company->id, 'workshop' => 'Алматы', 'name' => $n, 'order' => $o, 'type' => 'project', 'is_active' => true, 'is_completed' => $o === 2]);
        }

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $deal = Deal::create(['number' => 'QT-001', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 100000, 'status' => 'active', 'company_id' => $company->id, 'deal_stage_id' => DealStage::orderBy('order')->first()->id]);

        return [$admin, $deal, $company];
    }

    public function test_two_workshops_require_choice_and_use_own_funnel(): void
    {
        [$admin, $deal] = $this->companyWithTwoWorkshops();

        // Без выбора цеха — ошибка, заказ не создан.
        $this->actingAs($admin)->post(route('deals.toWorkshop', $deal->id))->assertSessionHas('error');
        $this->assertNull($deal->fresh()->project);

        // С выбором «Алматы» — заказ на ПЕРВОМ этапе воронки этого цеха.
        $this->actingAs($admin)->post(route('deals.toWorkshop', $deal->id), ['workshop' => 'Алматы'])->assertSessionHas('success');
        $project = $deal->fresh()->project;
        $this->assertSame('Алматы', $project->workshop);
        $this->assertSame('Формовка А', $project->stage->name);

        // «Далее» двигает по воронке СВОЕГО цеха (Формовка А → Упаковка А, не в металл).
        $this->actingAs($admin)->patch(route('projects.advance', $project->id));
        $this->assertSame('Упаковка А', $project->fresh()->stage->name);
    }

    public function test_single_workshop_company_needs_no_choice(): void
    {
        $company = Company::firstOrCreate(['code' => 'ALT'], ['name' => 'ALT']);
        ProjectStage::create(['company_id' => $company->id, 'name' => 'Пошив', 'order' => 1, 'type' => 'project', 'is_active' => true]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $deal = Deal::create(['number' => 'ALT-001', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 100, 'status' => 'active', 'company_id' => $company->id, 'deal_stage_id' => DealStage::orderBy('order')->first()->id]);

        $this->actingAs($admin)->post(route('deals.toWorkshop', $deal->id))->assertSessionHas('success');
        $this->assertSame('Пошив', $deal->fresh()->project->stage->name);
        $this->assertNull($deal->fresh()->project->workshop);
    }
}
