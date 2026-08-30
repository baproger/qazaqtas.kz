<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Модуль задач: страница по праву, быстрые действия, автосинхронизация со сделкой. */
class TasksEngineTest extends TestCase
{
    use RefreshDatabase;

    private int $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->company = Company::where('code', 'QT')->value('id');
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->companies()->attach($this->company);

        return $u;
    }

    public function test_page_follows_task_permission_and_scope(): void
    {
        $mgr = $this->user('manager');
        $other = $this->user('manager');
        Task::create(['title' => 'Моя', 'assignee_id' => $mgr->id, 'creator_id' => $other->id, 'status' => 'new']);
        Task::create(['title' => 'Чужая', 'assignee_id' => $other->id, 'creator_id' => $other->id, 'status' => 'new']);

        $this->actingAs($mgr)->get(route('tasks.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Tasks/Index')->has('tasks.data', 1)->where('tasks.data.0.title', 'Моя')->where('canSeeAll', false));

        Role::findByName('manager')->revokePermissionTo('task.viewAny');
        $this->actingAs($mgr)->get(route('tasks.index'))->assertForbidden();
    }

    public function test_toggle_autosave_and_type_derivation(): void
    {
        $mgr = $this->user('manager');
        $task = Task::create(['title' => 'Позвонить', 'assignee_id' => $mgr->id, 'creator_id' => $mgr->id, 'status' => 'new']);
        $this->assertSame('corporate', $task->type);

        $this->actingAs($mgr)->patch(route('tasks.toggle', $task))->assertRedirect();
        $this->assertSame('done', $task->fresh()->status);
        $this->assertNotNull($task->fresh()->completed_at);

        $this->actingAs($mgr)->patch(route('tasks.autosave', $task), ['title' => 'Позвонить клиенту', 'due_date' => '2026-09-01'])->assertRedirect();
        $this->assertSame('Позвонить клиенту', $task->fresh()->title);
        $this->assertSame('2026-09-01', $task->fresh()->due_date->toDateString());

        $this->actingAs($mgr)->patch(route('tasks.status', $task), ['status' => 'canceled'])->assertRedirect();
        $this->assertSame('canceled', $task->fresh()->status);
    }

    public function test_closing_gate_task_advances_deal_when_rule_is_on(): void
    {
        $a = DealStage::create(['company_id' => $this->company, 'name' => 'A', 'order' => 1, 'color' => '#000', 'type' => 'sale', 'is_active' => true, 'checklist' => [],
            'gate_task_title' => 'Проверить', 'gate_task_role' => 'responsible', 'gate_task_days' => 2, 'rules' => ['advance_on_gate' => true]]);
        $b = DealStage::create(['company_id' => $this->company, 'name' => 'B', 'order' => 2, 'color' => '#000', 'type' => 'sale', 'is_active' => true, 'checklist' => []]);
        $mgr = $this->user('manager');
        $deal = Deal::create(['company_id' => $this->company, 'number' => 'T-1', 'name' => 'X', 'company_name' => 'ТОО', 'budget' => 1, 'status' => 'active', 'deal_stage_id' => $b->id, 'responsible_user_id' => $mgr->id]);

        // Вход на A ставит гейт-задачу ответственному.
        $this->actingAs($mgr);
        app(StageTransitionService::class)->moveToStage($deal, $a);
        $task = $deal->tasks()->where('title', 'like', 'Проверить%')->firstOrFail();
        $this->assertSame('crm_deal', $task->type);
        $this->assertSame($mgr->id, $task->assignee_id);

        // Закрыли задачу со страницы задач — сделка сама ушла на B.
        $this->actingAs($mgr)->patch(route('tasks.toggle', $task))->assertRedirect();
        $this->assertSame($b->id, $deal->fresh()->deal_stage_id);
    }

    public function test_task_page_kanban_move_and_comment(): void
    {
        $mgr = $this->user('manager');
        $a = Task::create(['title' => 'A', 'assignee_id' => $mgr->id, 'creator_id' => $mgr->id, 'status' => 'new']);
        $b = Task::create(['title' => 'B', 'assignee_id' => $mgr->id, 'creator_id' => $mgr->id, 'status' => 'in_progress']);

        $this->actingAs($mgr)->get(route('tasks.show', $a))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Tasks/Show')->where('task.title', 'A')->where('canEdit', true));

        // Перенос A в колонку «В работе» перед B.
        $this->actingAs($mgr)->patch(route('tasks.move', $a), ['status' => 'in_progress', 'order' => [$a->id, $b->id]])->assertRedirect();
        $this->assertSame('in_progress', $a->fresh()->status);
        $this->assertSame([0, 1], [$a->fresh()->position, $b->fresh()->position]);

        $this->actingAs($mgr)->post(route('comments.store'), ['commentable_type' => 'task', 'commentable_id' => $a->id, 'body' => 'Готово?'])->assertRedirect();
        $this->assertSame(1, $a->comments()->count());

        // Чужую задачу без права смотреть нельзя.
        $stranger = $this->user('foreman');
        $this->actingAs($stranger)->get(route('tasks.show', $a))->assertForbidden();
    }

    public function test_live_version_stamp_changes_and_supports_etag(): void
    {
        $this->get(route('live.version'))->assertRedirect(); // гость — на вход
        $mgr = $this->user('manager');
        $r1 = $this->actingAs($mgr)->getJson(route('live.version'))->assertOk();
        $etag = $r1->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->actingAs($mgr)->getJson(route('live.version'), ['If-None-Match' => $etag])->assertStatus(304);

        // Новая задача сдвигает штамп через событие модели — без запросов к БД при опросе.
        Task::create(['title' => 'N', 'assignee_id' => $mgr->id, 'creator_id' => $mgr->id, 'status' => 'new']);
        $r2 = $this->actingAs($mgr)->getJson(route('live.version'), ['If-None-Match' => $etag])->assertOk();
        $this->assertGreaterThan(0, $r2->json('tasks'));
        // Уведомление — тоже.
        $etag2 = $r2->headers->get('ETag');
        $mgr->notify(new TaskAssigned(Task::first()));
        $this->actingAs($mgr)->getJson(route('live.version'), ['If-None-Match' => $etag2])->assertOk();
    }
}
