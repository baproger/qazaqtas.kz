<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealsReportTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    public function test_report_renders_for_admin_and_director(): void
    {
        $this->actingAs($this->user('admin'))->get(route('reports.deals'))->assertOk();
        $this->actingAs($this->user('director'))->get(route('reports.deals'))->assertOk();
    }

    public function test_report_forbidden_for_financist_manager_employee(): void
    {
        // Отчёт показывает бонусы ВСЕХ менеджеров — только admin/director.
        foreach (['financist', 'manager', 'employee'] as $role) {
            $this->actingAs($this->user($role))->get(route('reports.deals'))->assertForbidden();
        }
    }

    public function test_report_renders_with_data_and_filters(): void
    {
        Deal::create([
            'number' => 'BAIA-R-1', 'name' => 'ТОО Тест', 'company_name' => 'ТОО Тест',
            'client_name' => 'парта', 'bin' => '990440002867', 'address' => 'Алматы',
            'budget' => 1966700, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);

        $this->actingAs($this->user('admin'))
            ->get(route('reports.deals', ['search' => 'ТОО', 'from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk();
    }
}
