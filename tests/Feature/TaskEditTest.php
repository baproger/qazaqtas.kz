<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskEditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_assignee_without_permission_can_edit_task(): void
    {
        $user = User::factory()->create(); // no role
        $this->assertFalse($user->can('task.update'));

        $task = Task::create(['title' => 'Старое', 'assignee_id' => $user->id, 'creator_id' => $user->id, 'priority' => 'medium', 'status' => 'new']);

        $this->actingAs($user)->put(route('tasks.update', $task), [
            'title' => 'Новое название', 'priority' => 'high', 'status' => 'in_progress',
            'due_date' => now()->addDay()->format('Y-m-d H:i:s'),
        ])->assertRedirect();

        $task->refresh();
        $this->assertEquals('Новое название', $task->title);
        $this->assertEquals('high', $task->priority);
        $this->assertEquals('in_progress', $task->status);
    }

    public function test_stranger_cannot_edit_task(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $task = Task::create(['title' => 'T', 'assignee_id' => $owner->id, 'creator_id' => $owner->id, 'priority' => 'low', 'status' => 'new']);

        $this->actingAs($stranger)->put(route('tasks.update', $task), ['title' => 'Взлом'])->assertForbidden();
    }
}
