<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskModuleTest extends TestCase
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

    // Tasks are managed inline inside deal/project cards (TaskPanel); there is no
    // standalone tasks board route anymore.

    public function test_create_task_on_deal_and_advance_status(): void
    {
        $u = $this->admin();
        $deal = Deal::create([
            'number' => 'BAIA-T-1', 'name' => 'D', 'budget' => 1, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);

        $this->actingAs($u)->post(route('tasks.store'), [
            'title' => 'Позвонить клиенту',
            'taskable_type' => 'deal',
            'taskable_id' => $deal->id,
            'assignee_id' => $u->id,
        ])->assertRedirect();

        $task = Task::first();
        $this->assertEquals('deal', $task->taskable_type);
        $this->assertEquals($deal->id, $task->taskable_id);
        $this->assertEquals($u->id, $task->creator_id);

        $this->actingAs($u)->patch(route('tasks.status', $task), ['status' => 'done'])->assertRedirect();
        $task->refresh();
        $this->assertEquals('done', $task->status);
        $this->assertNotNull($task->completed_at);
    }
}
