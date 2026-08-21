<?php

namespace Tests\Feature;

use App\Models\BonusPayout;
use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\BonusPayoutService;
use App\Services\FinanceService;
use App\Services\PayrollService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Бонус можно копить и забрать разом.
 *
 * Поэтому «сколько сотруднику должны» — это начислено минус выплачено, а не
 * бонус текущего месяца.
 */
class BonusPayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->accountant = User::factory()->create();
        $this->accountant->assignRole('financist');
        $this->accountant->companies()->attach(Company::where('code', 'QT')->value('id'));

        $this->manager = User::factory()->create(['salary' => 100000]);
        $this->manager->assignRole('manager');
        $this->manager->companies()->attach(Company::where('code', 'QT')->value('id'));
    }

    /** Выигранная сделка, оплаченная в указанную дату. */
    private function wonDeal(string $paidOn, float $budget = 1000000): Deal
    {
        $deal = Deal::create([
            'company_id' => Company::where('code', 'QT')->value('id'),
            'number' => 'QT-'.uniqid(), 'name' => 'Сделка', 'company_name' => 'Клиент',
            'budget' => $budget, 'status' => 'active', 'contract_date' => $paidOn,
            'deal_stage_id' => DealStage::where('is_won', true)->value('id'),
            'responsible_user_id' => $this->manager->id,
        ]);

        $invoice = Invoice::create([
            'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id,
            'number' => 'INV-'.uniqid(), 'amount' => $budget, 'status' => 'sent',
        ]);
        Payment::create([
            'invoice_id' => $invoice->id, 'amount' => $budget,
            'payment_date' => $paidOn, 'payment_method' => 'bank',
        ]);

        return $deal;
    }

    /** Год показывает начисления по месяцам оплаты и годовой итог. */
    public function test_year_shows_accruals_by_payment_month(): void
    {
        $this->wonDeal('2026-06-15');
        $this->wonDeal('2026-09-20', 500000);

        $rows = app(BonusPayoutService::class)->yearFor(collect([$this->manager]), 2026);
        $months = collect($rows[0]['months'])->keyBy('month');

        $this->assertGreaterThan(0, $months['2026-06']['accrued']);
        $this->assertGreaterThan(0, $months['2026-09']['accrued']);
        $this->assertSame(0.0, $months['2026-07']['accrued'], 'В июле оплат не было.');
        $this->assertSame(
            round($months['2026-06']['accrued'] + $months['2026-09']['accrued'], 2),
            round($rows[0]['accrued'], 2),
        );
    }

    /** Пока бонус не выплачен — он копится и висит долгом перед сотрудником. */
    public function test_unpaid_bonus_accumulates(): void
    {
        $this->wonDeal('2026-06-15');

        $rows = app(BonusPayoutService::class)->yearFor(collect([$this->manager]), 2026);

        $this->assertSame(0.0, $rows[0]['paid']);
        $this->assertSame($rows[0]['accrued'], $rows[0]['left'], 'Ничего не выплачено — всё накоплено.');
    }

    /** Выплата уменьшает кассу и закрывает месяц. */
    public function test_payout_takes_money_and_closes_the_month(): void
    {
        $this->wonDeal('2026-06-15');
        $before = app(FinanceService::class)->companyBalances(null)['cash'];
        $accrued = app(PayrollService::class)->bonusByUserForMonth($this->manager->id, '2026-06');

        $this->actingAs($this->accountant)->post(route('bonuses.pay'), [
            'user_id' => $this->manager->id,
            'months' => ['2026-06'],
            'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $payout = BonusPayout::firstOrFail();
        $this->assertSame(round($accrued, 2), (float) $payout->amount);

        $expense = Expense::findOrFail($payout->expense_id);
        $this->assertSame('bonus', $expense->employee_payout);
        $this->assertSame($this->manager->id, $expense->employee_id);
        $this->assertSame(round($before - $accrued, 2), round(app(FinanceService::class)->companyBalances(null)['cash'], 2));

        $rows = app(BonusPayoutService::class)->yearFor(collect([$this->manager]), 2026);
        $this->assertSame(0.0, $rows[0]['left'], 'Месяц закрыт — копить больше нечего.');
    }

    /** Повторная выплата того же месяца ничего не выдаёт. */
    public function test_second_payout_of_the_same_month_pays_nothing(): void
    {
        $this->wonDeal('2026-06-15');
        $payload = [
            'user_id' => $this->manager->id,
            'months' => ['2026-06'],
            'payment_method' => 'cash',
        ];

        $this->actingAs($this->accountant)->post(route('bonuses.pay'), $payload)->assertSessionHasNoErrors();
        $this->actingAs($this->accountant)->post(route('bonuses.pay'), $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, BonusPayout::count(), 'Двойная выплата бонуса за месяц недопустима.');
    }

    /** Несколько накопленных месяцев забираются одной выплатой. */
    public function test_several_saved_months_are_paid_at_once(): void
    {
        $this->wonDeal('2026-06-15', 400000);
        $this->wonDeal('2026-07-15', 600000);

        $this->actingAs($this->accountant)->post(route('bonuses.pay'), [
            'user_id' => $this->manager->id,
            'months' => ['2026-06', '2026-07'],
            'payment_method' => 'bank',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, BonusPayout::count());
        $rows = app(BonusPayoutService::class)->yearFor(collect([$this->manager]), 2026);
        $this->assertSame($rows[0]['accrued'], $rows[0]['paid']);
        $this->assertSame(0.0, $rows[0]['left']);
    }

    /** В «К выплате» ведомости идёт только НЕвыплаченный бонус. */
    public function test_payroll_counts_only_unpaid_bonus(): void
    {
        $this->wonDeal('2026-06-15');

        $before = null;
        $this->actingAs($this->accountant)->get(route('payroll.index', ['month' => '2026-06']))
            ->assertInertia(function ($page) use (&$before) {
                $before = collect($page->toArray()['props']['rows'])->firstWhere('uid', $this->manager->id);
            });
        $this->assertGreaterThan(0, (float) $before['bonus_left']);

        $this->actingAs($this->accountant)->post(route('bonuses.pay'), [
            'user_id' => $this->manager->id, 'months' => ['2026-06'], 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->accountant)->get(route('payroll.index', ['month' => '2026-06']))
            ->assertInertia(function ($page) use ($before) {
                $row = collect($page->toArray()['props']['rows'])->firstWhere('uid', $this->manager->id);

                $this->assertSame(0.0, (float) $row['bonus_left'], 'Выплаченный бонус остаётся в «К выплате».');
                $this->assertSame(
                    round((float) $before['payout'] - (float) $before['bonus_left'], 2),
                    round((float) $row['payout'], 2),
                );
            });
    }

    /** Отмена выплаты возвращает деньги и снова копит месяц. */
    public function test_cancelling_a_payout_returns_the_money(): void
    {
        $this->wonDeal('2026-06-15');
        $before = app(FinanceService::class)->companyBalances(null)['cash'];

        $this->actingAs($this->accountant)->post(route('bonuses.pay'), [
            'user_id' => $this->manager->id, 'months' => ['2026-06'], 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $payout = BonusPayout::firstOrFail();
        $this->actingAs($this->accountant)->delete(route('bonuses.destroy', $payout->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, BonusPayout::count());
        $this->assertNull(Expense::find($payout->expense_id));
        $this->assertSame(round($before, 2), round(app(FinanceService::class)->companyBalances(null)['cash'], 2));
    }

    /** Бонус выплачивает бухгалтер: менеджер сам себе его не выдаст. */
    public function test_manager_cannot_pay_bonus(): void
    {
        $this->wonDeal('2026-06-15');

        $this->actingAs($this->manager)->post(route('bonuses.pay'), [
            'user_id' => $this->manager->id, 'months' => ['2026-06'], 'payment_method' => 'cash',
        ])->assertForbidden();
    }

    /** Сотрудник видит на странице только свою строку. */
    public function test_employee_sees_only_his_own_row(): void
    {
        $this->wonDeal('2026-06-15');
        $worker = User::factory()->create();
        $worker->assignRole('employee');
        $worker->companies()->attach(Company::where('code', 'QT')->value('id'));

        $this->actingAs($worker)->get(route('bonuses.index', ['year' => 2026]))
            ->assertInertia(fn ($page) => $page->component('Finance/Bonuses')->has('rows', 0));

        $this->actingAs($this->accountant)->get(route('bonuses.index', ['year' => 2026]))
            ->assertInertia(fn ($page) => $page->has('rows', 1));
    }
}
