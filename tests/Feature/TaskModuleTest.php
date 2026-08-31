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
            'number' => 'QT-T-1', 'name' => 'D', 'budget' => 1, 'status' => 'active',
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

    public function test_status_all_is_no_filter_and_default_is_open(): void
    {
        $u = $this->admin();
        $other = User::factory()->create();
        Task::forceCreate(['title' => 'Открытая', 'creator_id' => $u->id, 'assignee_id' => $other->id, 'status' => 'new', 'type' => 'corporate', 'priority' => 'medium']);
        Task::forceCreate(['title' => 'Готовая', 'creator_id' => $u->id, 'assignee_id' => $other->id, 'status' => 'done', 'type' => 'corporate', 'priority' => 'medium']);

        // «Все статусы» — отсутствие фильтра, а не статус с именем all.
        // Раньше all уходил в where('status','all') и страница была пустой.
        $this->actingAs($u)->get(route('tasks.index', ['view' => 'created', 'status' => 'all']))
            ->assertInertia(fn ($p) => $p->has('tasks.data', 2)->where('filters.status', 'all'));

        // Канбан всегда просит все статусы — он тоже не должен пустеть.
        $this->actingAs($u)->get(route('tasks.index', ['view' => 'created', 'status' => 'all', 'mode' => 'board']))
            ->assertInertia(fn ($p) => $p->has('tasks.data', 2));

        // Без параметра статуса показываются только открытые — как и написано в селекте.
        $this->actingAs($u)->get(route('tasks.index', ['view' => 'created']))
            ->assertInertia(fn ($p) => $p->has('tasks.data', 1)->where('filters.status', 'open'));
    }
}
