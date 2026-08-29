<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\DealStageChanged;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Конструктор логики этапа: правила собирает владелец, код только проверяет.
 * Без сохранённых правил действует то, что раньше было зашито за типом.
 */
class StageRulesTest extends TestCase
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
        $mk = fn (string $name, int $order, array $extra = []) => DealStage::create(array_merge([
            'company_id' => $this->company, 'name' => $name, 'order' => $order, 'color' => '#000', 'type' => 'sale', 'is_active' => true, 'checklist' => [],
        ], $extra));
        $this->a = $mk('Первый', 1);
        $this->b = $mk('Второй', 2);
        $this->c = $mk('Третий', 3);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->companies()->attach($this->company);

        return $u;
    }

    private function deal(User $owner, DealStage $stage): Deal
    {
        return Deal::create(['company_id' => $this->company, 'number' => 'R-'.uniqid(), 'name' => 'X', 'company_name' => 'ТОО',
            'budget' => 100000, 'status' => 'active', 'deal_stage_id' => $stage->id, 'responsible_user_id' => $owner->id]);
    }

    private function move(User $u, Deal $d, DealStage $to)
    {
        return $this->actingAs($u)->withSession(['company_id' => $this->company])
            ->patch(route('deals.stage', $d->id), ['deal_stage_id' => $to->id]);
    }

    public function test_enter_roles_restrict_who_moves_onto_the_stage(): void
    {
        $this->b->update(['rules' => ['enter_roles' => ['financist']]]);
        $mgr = $this->user('manager');
        $deal = $this->deal($mgr, $this->a);

        $this->move($mgr, $deal, $this->b)->assertSessionHas('error');
        $this->assertSame($this->a->id, $deal->fresh()->deal_stage_id);

        $this->move($this->user('financist'), $deal, $this->b)->assertSessionHas('success');
        $this->assertSame($this->b->id, $deal->fresh()->deal_stage_id);
    }

    public function test_leave_roles_and_from_stages(): void
    {
        $this->b->update(['rules' => ['leave_roles' => ['financist']]]);
        $this->c->update(['rules' => ['from_stages' => [$this->b->id]]]);
        $mgr = $this->user('manager');

        // На третий — только со второго.
        $deal = $this->deal($mgr, $this->a);
        $this->move($mgr, $deal, $this->c)->assertSessionHas('error');

        // Со второго уводит только бухгалтер.
        $deal->update(['deal_stage_id' => $this->b->id]);
        $this->move($mgr, $deal, $this->c)->assertSessionHas('error');
        $this->move($this->user('financist'), $deal, $this->c)->assertSessionHas('success');
        $this->assertSame($this->c->id, $deal->fresh()->deal_stage_id);
    }

    public function test_payment_requirement_blocks_until_paid(): void
    {
        $this->a->update(['rules' => ['require' => ['payment' => 'full']]]);
        $mgr = $this->user('manager');
        $deal = $this->deal($mgr, $this->a);
        $inv = Invoice::create(['number' => 'I-1', 'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id, 'amount' => 100000, 'status' => 'sent', 'issue_date' => now()->toDateString()]);

        $this->move($mgr, $deal, $this->b)->assertSessionHas('error');
        Payment::create(['invoice_id' => $inv->id, 'amount' => 40000, 'payment_date' => now()->toDateString()]);
        $this->move($mgr, $deal, $this->b)->assertSessionHas('error');
        Payment::create(['invoice_id' => $inv->id, 'amount' => 60000, 'payment_date' => now()->toDateString()]);
        $this->move($mgr, $deal, $this->b)->assertSessionHas('success');
    }

    public function test_extra_movers_may_advance_without_edit_rights(): void
    {
        Notification::fake();
        $this->a->update(['rules' => ['extra_movers' => ['supplier']]]);
        $director = $this->user('director');
        $supplier = $this->user('supplier');
        $deal = $this->deal($this->user('manager'), $this->a);

        // Снабженец не правит сделку, но по правилу этапа жмёт «Далее».
        $this->actingAs($supplier)->withSession(['company_id' => $this->company])
            ->patch(route('deals.advance', $deal->id))->assertSessionHas('success');
        $this->assertSame($this->b->id, $deal->fresh()->deal_stage_id);
        // Рассылки «всем с ролью» из условий убраны: директору ничего не приходит — это дело роботов.
        Notification::assertNotSentTo($director, DealStageChanged::class);
    }

    public function test_admin_ignores_role_rules_and_rules_survive_in_the_settings_page(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->withSession(['company_id' => $this->company])
            ->put(route('stages.update', ['deal', $this->b->id]), [
                'name' => 'Второй', 'rules' => ['enter_roles' => ['financist', 'no-such-role'], 'from_stages' => [$this->a->id, 999], 'require' => ['payment' => 'partial']],
            ])->assertSessionHasNoErrors();

        $rules = $this->b->fresh()->effectiveRules();
        $this->assertSame(['financist'], $rules['enter_roles']);
        $this->assertSame([$this->a->id], $rules['from_stages']);
        $this->assertSame('partial', $rules['require']['payment']);

        // Админ не ограничен ролями (enter_roles = бухгалтер — его не держит),
        // а требования сделки действуют и для него: уйти со второго без оплаты нельзя.
        $deal = $this->deal($admin, $this->a);
        $this->move($admin, $deal, $this->b)->assertSessionHas('success');
        $this->move($admin, $deal, $this->c)->assertSessionHas('error');
    }

    public function test_duplicate_copies_logic_but_not_the_system_type(): void
    {
        $this->b->update(['stage_type' => 'shop_gate', 'rules' => ['leave_roles' => ['financist']], 'gate_task_title' => 'Выставить акт', 'gate_task_role' => 'financist', 'gate_task_days' => 3]);
        $admin = $this->user('admin');

        $this->actingAs($admin)->withSession(['company_id' => $this->company])
            ->post(route('stages.duplicate', ['deal', $this->b->id]))->assertRedirect();

        $copy = DealStage::where('name', 'Второй (копия)')->firstOrFail();
        $this->assertNull($copy->stage_type);
        $this->assertSame(['financist'], $copy->effectiveRules()['leave_roles']);
        $this->assertSame('Выставить акт', $copy->gate_task_title);
        $this->assertSame([1, 2, 3, 4], DealStage::where('company_id', $this->company)->orderBy('order')->pluck('order')->all());
        $this->assertSame(3, $copy->fresh()->order);
    }

    /** Гейт-задача ставится ОДНОМУ адресату — ответственному, а не всем с ролью. */
    public function test_gate_task_goes_to_the_responsible_only(): void
    {
        $this->b->update(['gate_task_title' => 'Проверить сделку', 'gate_task_role' => 'responsible', 'gate_task_days' => 2]);
        $owner = $this->user('manager');
        $other = $this->user('manager');
        $deal = $this->deal($owner, $this->a);

        $this->move($owner, $deal, $this->b)->assertSessionHas('success');

        $tasks = $deal->tasks()->where('title', 'like', 'Проверить сделку%')->get();
        $this->assertCount(1, $tasks);
        $this->assertSame($owner->id, $tasks->first()->assignee_id);

        // Галочку гейта ставит адресат, а не любой менеджер.
        $this->actingAs($other)->withSession(['company_id' => $this->company])->patch(route('deals.stageTask', $deal->id))->assertForbidden();
        $this->actingAs($owner)->withSession(['company_id' => $this->company])->patch(route('deals.stageTask', $deal->id))->assertRedirect();
        $this->assertSame('done', $tasks->first()->fresh()->status);
    }
}
