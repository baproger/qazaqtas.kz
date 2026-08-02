<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OverdueNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_overdue_task_notifies_assignee_once(): void
    {
        $user = User::factory()->create();
        $task = Task::create([
            'title' => 'Просроченная', 'assignee_id' => $user->id, 'creator_id' => $user->id,
            'priority' => 'high', 'status' => 'in_progress', 'due_date' => now()->subDay(),
        ]);

        $this->artisan('tasks:notify-overdue')->assertSuccessful();

        $this->assertEquals(1, $user->notifications()->count());
        $this->assertEquals('task_overdue', $user->notifications()->first()->data['type']);
        $this->assertNotNull($task->fresh()->overdue_notified_at);

        // Second run must not duplicate.
        $this->artisan('tasks:notify-overdue')->assertSuccessful();
        $this->assertEquals(1, $user->fresh()->notifications()->count());
    }

    public function test_done_task_is_not_notified(): void
    {
        $user = User::factory()->create();
        Task::create([
            'title' => 'Готово', 'assignee_id' => $user->id, 'creator_id' => $user->id,
            'priority' => 'low', 'status' => 'done', 'due_date' => now()->subDay(),
        ]);

        $this->artisan('tasks:notify-overdue')->assertSuccessful();
        $this->assertEquals(0, $user->notifications()->count());
    }
}
