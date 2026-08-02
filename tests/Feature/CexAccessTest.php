<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CexAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    public function test_workshop_employee_sees_all_cex_cards(): void
    {
        $emp = $this->user('employee');
        $other = $this->user('manager');
        $s = ProjectStage::orderBy('order')->first()->id;
        Project::create(['number' => 'P-1', 'name' => 'Мой', 'budget' => 1, 'status' => 'active', 'project_stage_id' => $s, 'responsible_user_id' => $emp->id]);
        Project::create(['number' => 'P-2', 'name' => 'Чужой', 'budget' => 1, 'status' => 'active', 'project_stage_id' => $s, 'responsible_user_id' => $other->id]);

        $this->actingAs($emp)->get(route('projects.index'))
            ->assertInertia(fn (Assert $p) => $p->component('Projects/Index')->has('projects', 2));
    }

    public function test_workshop_employee_can_advance_process(): void
    {
        $emp = $this->user('employee');
        $stages = ProjectStage::orderBy('order')->take(2)->get();
        $project = Project::create(['number' => 'P-3', 'name' => 'X', 'budget' => 1, 'status' => 'active', 'project_stage_id' => $stages[0]->id, 'responsible_user_id' => null]);

        $this->actingAs($emp)->patch(route('projects.advance', $project))->assertRedirect();
        $this->assertEquals($stages[1]->id, $project->fresh()->project_stage_id);
    }

    // Finance is leadership-only: managers/workshop staff handle money inside deal cards,
    // so the standalone Finance page must be forbidden to them.
    public function test_manager_cannot_access_finance(): void
    {
        $mgr = $this->user('manager');
        $this->actingAs($mgr)->get(route('finance.index'))->assertForbidden();
    }
}
