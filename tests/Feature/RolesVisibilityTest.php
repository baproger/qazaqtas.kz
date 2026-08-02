<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RolesVisibilityTest extends TestCase
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

    public function test_director_and_financist_roles_exist(): void
    {
        $this->assertTrue(\Spatie\Permission\Models\Role::whereIn('name', ['director', 'financist'])->count() === 2);
    }

    public function test_manager_sees_only_own_deals(): void
    {
        $mgr = $this->user('manager');
        $other = $this->user('manager');
        $stage = DealStage::orderBy('order')->first()->id;
        Deal::create(['number' => 'D-1', 'name' => 'Мой', 'budget' => 1, 'status' => 'active', 'deal_stage_id' => $stage, 'responsible_user_id' => $mgr->id]);
        Deal::create(['number' => 'D-2', 'name' => 'Чужой', 'budget' => 1, 'status' => 'active', 'deal_stage_id' => $stage, 'responsible_user_id' => $other->id]);

        $this->actingAs($mgr)->get(route('deals.index'))
            ->assertInertia(fn (Assert $p) => $p->component('Deals/Index')->has('deals', 1));
    }

    public function test_workshop_employee_does_not_see_money(): void
    {
        $emp = $this->user('employee');
        $project = Project::create(['number' => 'PRJ-1', 'name' => 'Заказ', 'budget' => 5000000, 'status' => 'active', 'project_stage_id' => ProjectStage::orderBy('order')->first()->id, 'responsible_user_id' => $emp->id]);

        $this->actingAs($emp)->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $p) => $p->where('canSeeMoney', false)->where('finance', null));
    }

    public function test_manager_sees_money_in_workshop(): void
    {
        $mgr = $this->user('manager');
        $project = Project::create(['number' => 'PRJ-2', 'name' => 'Заказ', 'budget' => 5000000, 'status' => 'active', 'project_stage_id' => ProjectStage::orderBy('order')->first()->id, 'responsible_user_id' => $mgr->id]);

        $this->actingAs($mgr)->get(route('projects.show', $project))
            ->assertInertia(fn (Assert $p) => $p->where('canSeeMoney', true)->where('finance.budget', 5000000));
    }

    public function test_only_admin_director_create_chat_group(): void
    {
        $emp = $this->user('employee');
        $admin = $this->user('admin');

        $this->actingAs($emp)->post(route('chat.store'), ['type' => 'group', 'name' => 'X'])->assertForbidden();
        $this->actingAs($admin)->post(route('chat.store'), ['type' => 'group', 'name' => 'Y'])->assertRedirect();
        $this->assertEquals(1, Chat::where('type', 'group')->count());
    }
}
