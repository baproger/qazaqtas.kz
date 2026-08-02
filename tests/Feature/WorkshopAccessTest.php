<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Доступ сотрудников по цехам QT (users.workshops): работник «Шымкента»
 * видит и двигает только свои заказы; пустой список = все цеха.
 */
class WorkshopAccessTest extends TestCase
{
    use RefreshDatabase;

    private function setupWorkshops(): array
    {
        $this->seed(RolePermissionSeeder::class);
        $metalStage = ProjectStage::create(['name' => 'Формовка', 'order' => 1, 'is_active' => true, 'workshop' => 'Шымкент']);
        $almatyStage = ProjectStage::create(['name' => 'Формовка', 'order' => 1, 'is_active' => true, 'workshop' => 'Алматы']);
        $shymkent = Project::create(['number' => 'PRJ-1', 'name' => 'Вазон', 'workshop' => 'Шымкент', 'project_stage_id' => $metalStage->id, 'status' => 'active']);
        $almaty = Project::create(['number' => 'PRJ-2', 'name' => 'Скамья', 'workshop' => 'Алматы', 'project_stage_id' => $almatyStage->id, 'status' => 'active']);

        return [$shymkent, $almaty];
    }

    public function test_restricted_employee_sees_and_moves_only_his_workshop(): void
    {
        [$shymkent, $almaty] = $this->setupWorkshops();
        $worker = User::factory()->create(['workshops' => ['Шымкент']]);
        $worker->assignRole('employee');

        // Канбан: только заказы и секции своего цеха.
        $page = $this->actingAs($worker)->get(route('projects.index'));
        $page->assertOk();
        $props = $page->viewData('page')['props'];
        $numbers = collect($props['projects'])->pluck('number');
        $this->assertTrue($numbers->contains('PRJ-1'));
        $this->assertFalse($numbers->contains('PRJ-2'));
        $this->assertFalse(collect($props['stages'])->contains(fn ($s) => $s['workshop'] === 'Алматы'));

        // Чужой цех: ни открыть, ни двинуть.
        $this->actingAs($worker)->get(route('projects.show', $almaty->id))->assertForbidden();
        $this->actingAs($worker)->patch(route('projects.advance', $almaty->id))->assertForbidden();

        // Свой цех — работает.
        $this->actingAs($worker)->patch(route('projects.advance', $shymkent->id))->assertRedirect();
    }

    public function test_employee_with_both_or_empty_sees_everything(): void
    {
        [$shymkent, $almaty] = $this->setupWorkshops();

        // Оба цеха.
        $both = User::factory()->create(['workshops' => ['Шымкент', 'Алматы']]);
        $both->assignRole('employee');
        $numbers = collect($this->actingAs($both)->get(route('projects.index'))
            ->viewData('page')['props']['projects'])->pluck('number');
        $this->assertTrue($numbers->contains('PRJ-1') && $numbers->contains('PRJ-2'));

        // Без ограничения (null) — как раньше, всё видно.
        $free = User::factory()->create();
        $free->assignRole('employee');
        $this->actingAs($free)->get(route('projects.show', $almaty->id))->assertOk();
    }
}
