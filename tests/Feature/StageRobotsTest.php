<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\StageRobot;
use App\Models\StageRobotRun;
use App\Models\User;
use App\Notifications\RobotNotification;
use App\Robots\Runner;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Роботы этапов: событийный слой поверх переходов. */
class StageRobotsTest extends TestCase
{
    use RefreshDatabase;

    private int $company;

    private DealStage $a;

    private DealStage $b;

    private DealStage $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->company = Company::where('code', 'QT')->value('id');
        $mk = fn ($n, $o) => DealStage::create(['company_id' => $this->company, 'name' => $n, 'order' => $o, 'color' => '#000', 'type' => 'sale', 'is_active' => true, 'checklist' => []]);
        $this->a = $mk('A', 1);
        $this->b = $mk('B', 2);
        $this->c = $mk('C', 3);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->companies()->attach($this->company);

        return $u;
    }

    private function deal(User $owner, float $budget = 100000): Deal
    {
        return Deal::create(['company_id' => $this->company, 'number' => 'RB-'.uniqid(), 'name' => 'X', 'company_name' => 'ТОО Ромашка',
            'budget' => $budget, 'status' => 'active', 'deal_stage_id' => $this->a->id, 'responsible_user_id' => $owner->id]);
    }

    private function robot(array $extra = []): StageRobot
    {
        return StageRobot::create(array_merge([
            'company_id' => $this->company, 'stage_id' => $this->b->id, 'trigger' => 'enter', 'name' => 'R', 'is_active' => true,
            'sequence' => 'parallel', 'delay_seconds' => 0, 'action_type' => 'send_notification',
            'action_payload' => ['roles' => ['director'], 'title' => 'Сделка {{deal.number}}', 'text' => '{{deal.company_name}} на {{stage.name}}: {{deal.budget|money}}'],
        ], $extra));
    }

    public function test_robot_fires_on_enter_with_placeholders_and_conditions(): void
    {
        Notification::fake();
        $director = $this->user('director');
        $this->robot(['conditions' => ['all' => [['field' => 'budget', 'op' => '>', 'value' => 50000]]]]);
        $small = $this->robot(['name' => 'small', 'conditions' => ['all' => [['field' => 'budget', 'op' => '<', 'value' => 10]]]]);

        $mgr = $this->user('manager');
        $deal = $this->deal($mgr);
        app(StageTransitionService::class)->moveToStage($deal, $this->b);

        Notification::assertSentTo($director, RobotNotification::class, fn ($n) => $n->title === 'Сделка '.$deal->number
            && str_contains($n->message, 'ТОО Ромашка на B: 100 000 ₸'));
        $this->assertSame(1, StageRobotRun::where('status', 'done')->count());
        $this->assertSame(0, StageRobotRun::where('robot_id', $small->id)->count(), 'Условие не прошло — запуска нет.');
    }

    public function test_delayed_robot_is_skipped_if_deal_left_the_stage(): void
    {
        Notification::fake();
        $director = $this->user('director');
        $robot = $this->robot(['delay_seconds' => 3600]);
        $deal = $this->deal($this->user('manager'));
        $svc = app(StageTransitionService::class);
        $svc->moveToStage($deal, $this->b);

        $run = StageRobotRun::firstOrFail();
        $this->assertSame('queued', $run->status);
        $this->assertNotNull($run->scheduled_at);
        $this->assertSame(0, app(Runner::class)->runDue(), 'Время не пришло.');

        $svc->moveToStage($deal->fresh(), $this->c);
        $this->travel(2)->hours();
        $this->assertSame(1, app(Runner::class)->runDue());
        $this->assertSame('skipped', $run->fresh()->status);
        Notification::assertNotSentTo($director, RobotNotification::class);

        // Повторный прогон ничего не делает — идемпотентность.
        $this->assertSame(0, app(Runner::class)->runDue());
    }

    public function test_assign_task_change_field_and_webhook_actions(): void
    {
        Http::fake(['hooks.example/*' => Http::response(['ok' => true], 200)]);
        $fin = $this->user('financist');
        $mgr = $this->user('manager');
        $this->robot(['name' => 'resp', 'action_type' => 'assign_responsible', 'action_payload' => ['role' => ['financist']]]);
        $this->robot(['name' => 'task', 'action_type' => 'create_task', 'action_payload' => ['title' => 'Позвонить {{deal.company_name}}', 'assignee' => 'responsible', 'days' => 2]]);
        $this->robot(['name' => 'field', 'action_type' => 'change_field', 'action_payload' => ['field' => 'note', 'value' => 'авто: {{stage.name}}']]);
        $this->robot(['name' => 'hook', 'action_type' => 'send_webhook', 'action_payload' => ['url' => 'https://hooks.example/deal', 'secret' => 's3']]);

        $deal = $this->deal($mgr);
        app(StageTransitionService::class)->moveToStage($deal, $this->b);

        $deal->refresh();
        $this->assertSame($fin->id, $deal->responsible_user_id);
        $this->assertSame('авто: B', $deal->note);
        $this->assertDatabaseHas('tasks', ['taskable_id' => $deal->id, 'title' => 'Позвонить ТОО Ромашка']);
        Http::assertSent(fn ($req) => $req->hasHeader('X-Signature') && $req['deal']['number'] === $deal->number);
        $this->assertSame(4, StageRobotRun::where('status', 'done')->count());
    }

    public function test_move_stage_action_chains_and_stops_at_depth(): void
    {
        $this->robot(['name' => 'to-c', 'action_type' => 'move_stage', 'action_payload' => ['stage_id' => $this->c->id]]);
        $deal = $this->deal($this->user('manager'));
        app(StageTransitionService::class)->moveToStage($deal, $this->b);
        $this->assertSame($this->c->id, $deal->fresh()->deal_stage_id);
    }

    public function test_settings_page_and_crud_for_admin_only(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($this->user('manager'))->get(route('robots.index'))->assertForbidden();
        $this->actingAs($admin)->withSession(['company_id' => $this->company])->post(route('robots.store'), [
            'name' => 'Новый', 'stage_id' => $this->b->id, 'trigger' => 'enter', 'sequence' => 'parallel', 'delay_seconds' => 120,
            'conditions' => ['all' => [['field' => 'budget', 'op' => '>=', 'value' => 1]]],
            'action_type' => 'send_notification', 'action_payload' => ['roles' => ['director'], 'title' => 't', 'text' => 'x'],
        ])->assertSessionHasNoErrors();
        $robot = StageRobot::firstOrFail();
        $this->assertSame(120, $robot->delay_seconds);

        $this->actingAs($admin)->post(route('robots.duplicate', $robot))->assertRedirect();
        $this->assertSame(2, StageRobot::count());
        $this->actingAs($admin)->withSession(['company_id' => $this->company])->get(route('robots.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('Settings/Robots')->has('robots', 2)->has('actions', 6));
    }
}
