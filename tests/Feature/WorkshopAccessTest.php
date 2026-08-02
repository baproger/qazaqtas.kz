<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Доступ сотрудников по цехам QT (users.workshops): работник «Металл цеха»
 * видит и двигает только свои заказы; пустой список = все цеха.
 */
class WorkshopAccessTest extends TestCase
{
    use RefreshDatabase;

    private function setupWorkshops(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $metalStage = ProjectStage::create(['name' => 'Кесу', 'order' => 1, 'is_active' => true, 'workshop' => 'Металл цех']);
        $agashStage = ProjectStage::create(['name' => 'Кесу', 'order' => 1, 'is_active' => true, 'workshop' => 'Ағаш цех']);
        $metal = Project::create(['number' => 'PRJ-1', 'name' => 'Стол', 'workshop' => 'Металл цех', 'project_stage_id' => $metalStage->id, 'status' => 'active']);
        $agash = Project::create(['number' => 'PRJ-2', 'name' => 'Шкаф', 'workshop' => 'Ағаш цех', 'project_stage_id' => $agashStage->id, 'status' => 'active']);

        return [$metal, $agash];
    }

    public function test_restricted_employee_sees_and_moves_only_his_workshop(): void
    {
        [$metal, $agash] = $this->setupWorkshops();
        $worker = User::factory()->create(['workshops' => ['Металл цех']]);
        $worker->assignRole('employee');

        // Канбан: только заказы и секции своего цеха.
        $page = $this->actingAs($worker)->get(route('projects.index'));
        $page->assertOk();
        $props = $page->viewData('page')['props'];
        $numbers = collect($props['projects'])->pluck('number');
        $this->assertTrue($numbers->contains('PRJ-1'));
        $this->assertFalse($numbers->contains('PRJ-2'));
        $this->assertFalse(collect($props['stages'])->contains(fn ($s) => $s['workshop'] === 'Ағаш цех'));

        // Чужой цех: ни открыть, ни двинуть.
        $this->actingAs($worker)->get(route('projects.show', $agash->id))->assertForbidden();
        $this->actingAs($worker)->patch(route('projects.advance', $agash->id))->assertForbidden();

        // Свой цех — работает.
        $this->actingAs($worker)->patch(route('projects.advance', $metal->id))->assertRedirect();
    }

    public function test_employee_with_both_or_empty_sees_everything(): void
    {
        [$metal, $agash] = $this->setupWorkshops();

        // Оба цеха.
        $both = User::factory()->create(['workshops' => ['Металл цех', 'Ағаш цех']]);
        $both->assignRole('employee');
        $numbers = collect($this->actingAs($both)->get(route('projects.index'))
            ->viewData('page')['props']['projects'])->pluck('number');
        $this->assertTrue($numbers->contains('PRJ-1') && $numbers->contains('PRJ-2'));

        // Без ограничения (null) — как раньше, всё видно.
        $free = User::factory()->create();
        $free->assignRole('employee');
        $this->actingAs($free)->get(route('projects.show', $agash->id))->assertOk();
    }
}
