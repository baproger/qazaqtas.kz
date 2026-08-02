<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditNotificationTest extends TestCase
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

    public function test_creating_and_updating_deal_writes_audit(): void
    {
        $u = $this->admin();
        $this->actingAs($u);

        $deal = Deal::create([
            'number' => 'BAIA-A-1', 'name' => 'Audit deal', 'budget' => 100, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'table_name' => 'deals', 'record_id' => $deal->id, 'action' => 'created', 'user_id' => $u->id,
        ]);

        $deal->update(['budget' => 500]);
        $this->assertDatabaseHas('audit_logs', [
            'table_name' => 'deals', 'record_id' => $deal->id, 'action' => 'updated',
            'field_name' => 'budget', 'old_value' => '100.00', 'new_value' => '500',
        ]);
    }

    public function test_audit_index_renders(): void
    {
        $u = $this->admin();
        $this->actingAs($u)->get(route('audit.index'))->assertOk();
    }

    public function test_task_assignment_notifies_assignee(): void
    {
        $creator = $this->admin();
        $assignee = User::factory()->create();

        $this->actingAs($creator)->post(route('tasks.store'), [
            'title' => 'Notify me', 'assignee_id' => $assignee->id,
        ])->assertRedirect();

        $this->assertEquals(1, $assignee->notifications()->count());
        $this->assertEquals('task_assigned', $assignee->notifications()->first()->data['type']);
    }

    public function test_stage_change_notifies_responsible_and_marks_read(): void
    {
        $u = $this->admin();
        // Start on the first stage and move one step forward. The won stage is reachable
        // only through the workshop flow, so we assert notification on a normal transition.
        $stages = DealStage::where('is_active', true)->orderBy('order')->take(2)->get();
        $deal = Deal::create([
            'number' => 'BAIA-A-2', 'name' => 'D', 'budget' => 1, 'status' => 'active',
            'responsible_user_id' => $u->id,
            'deal_stage_id' => $stages[0]->id,
        ]);

        $this->actingAs($u)->patch(route('deals.stage', $deal), ['deal_stage_id' => $stages[1]->id])->assertRedirect();
        $this->assertEquals(1, $u->unreadNotifications()->count());

        $this->actingAs($u)->patch(route('notifications.readAll'))->assertRedirect();
        $this->assertEquals(0, $u->fresh()->unreadNotifications()->count());
    }
}
