<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\PayrollService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Бонус за месяц — единственная точка расчёта для долгов и ведомости.
 *
 * Месяц бонуса — тот, когда пришли ДЕНЬГИ от клиента (решение владельца от
 * 21.08.2026): бонус платится с денег, а не с подписанного договора. Сделка,
 * оплаченная частями, отдаёт бонус теми же частями по своим месяцам.
 */
class BonusByMonthTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private PayrollService $payroll;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->payroll = app(PayrollService::class);
    }

    /** Выигранная сделка с полной оплатой в нужном месяце. */
    private function wonDeal(string $contractDate, float $budget = 1000000, ?float $paid = null): Deal
    {
        $deal = Deal::create([
            'number' => 'QT-'.uniqid(),
            'name' => 'Сделка',
            'company_name' => 'Сделка',
            'budget' => $budget,
            'status' => 'active',
            'contract_date' => $contractDate,
            'deal_stage_id' => DealStage::where('is_won', true)->value('id'),
            'responsible_user_id' => $this->manager->id,
        ]);

        $invoice = Invoice::create([
            'invoiceable_type' => 'deal',
            'invoiceable_id' => $deal->id,
            'number' => 'INV-'.uniqid(),
            'amount' => $budget,
            'status' => 'sent',
        ]);
        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => $paid ?? $budget,
            'payment_date' => $contractDate,
            'payment_method' => 'bank',
        ]);

        return $deal;
    }

    public function test_deal_outside_the_month_is_not_counted(): void
    {
        $this->wonDeal('2026-07-15');

        $this->assertSame(0.0, $this->payroll->bonusByUserForMonth($this->manager->id, '2026-08'));
        $this->assertGreaterThan(0, $this->payroll->bonusByUserForMonth($this->manager->id, '2026-07'));
    }

    public function test_month_is_taken_from_the_payment_date(): void
    {
        // Оплата в августе, запись создана позже — считаем по оплате.
        $deal = $this->wonDeal('2026-08-10');
        $deal->forceFill(['created_at' => '2026-09-20 10:00:00'])->save();

        $this->assertGreaterThan(0, $this->payroll->bonusByUserForMonth($this->manager->id, '2026-08'));
        $this->assertSame(0.0, $this->payroll->bonusByUserForMonth($this->manager->id, '2026-09'));
    }

    /**
     * Дата договора на месяц бонуса больше не влияет: важно, когда пришли
     * деньги. Договор августовский, оплата — сентябрьская.
     */
    public function test_contract_date_does_not_decide_the_month(): void
    {
        $deal = $this->wonDeal('2026-08-10');
        \App\Models\Payment::query()->update(['payment_date' => '2026-09-05']);

        $this->assertSame(0.0, $this->payroll->bonusByUserForMonth($this->manager->id, '2026-08'));
        $this->assertGreaterThan(0, $this->payroll->bonusByUserForMonth($this->manager->id, '2026-09'));
        $this->assertNotNull($deal->fresh()->contract_date);
    }

    /** Частичные оплаты делят бонус по своим месяцам, а сумма сходится. */
    public function test_partial_payments_split_the_bonus_across_months(): void
    {
        $deal = $this->wonDeal('2026-07-01', 1000000, 400000);   // 40% в июле
        $invoice = \App\Models\Invoice::where('invoiceable_id', $deal->id)->firstOrFail();
        \App\Models\Payment::create([
            'invoice_id' => $invoice->id, 'amount' => 600000,
            'payment_date' => '2026-08-15', 'payment_method' => 'bank',
        ]);

        $july = $this->payroll->bonusByUserForMonth($this->manager->id, '2026-07');
        $august = $this->payroll->bonusByUserForMonth($this->manager->id, '2026-08');

        $this->assertGreaterThan(0, $july);
        $this->assertGreaterThan(0, $august);
        // 40% и 60% одного бонуса: август в полтора раза больше июля.
        $this->assertEqualsWithDelta($july * 1.5, $august, 1.0);
    }

    /**
     * Бонус пропорционален фактической оплате: выигранная, но неоплаченная
     * сделка не даёт полный бонус авансом.
     */
    public function test_bonus_follows_the_paid_share(): void
    {
        $this->wonDeal('2026-08-10', 1000000, 500000);

        $half = $this->payroll->bonusByUserForMonth($this->manager->id, '2026-08');

        // Для сравнения — та же сделка, оплаченная целиком.
        Payment::query()->delete();
        $invoice = Invoice::first();
        Payment::create([
            'invoice_id' => $invoice->id,
            'amount' => 1000000,
            'payment_date' => '2026-08-10',
            'payment_method' => 'bank',
        ]);

        $full = $this->payroll->bonusByUserForMonth($this->manager->id, '2026-08');

        $this->assertGreaterThan(0, $half);
        $this->assertEqualsWithDelta($full / 2, $half, 0.02);
    }

    /** Ручной % по сделке перекрывает ступени — метод обязан его уважать. */
    public function test_manual_bonus_rate_is_respected(): void
    {
        $deal = $this->wonDeal('2026-08-10');
        $auto = $this->payroll->bonusByUserForMonth($this->manager->id, '2026-08');

        $deal->update(['bonus_rate_override' => 50]);
        \App\Services\PayrollService::forgetBonusPercents();

        $manual = $this->payroll->bonusByUserForMonth($this->manager->id, '2026-08');

        $this->assertGreaterThan($auto, $manual);
    }

    public function test_empty_month_returns_zero(): void
    {
        $this->assertSame(0.0, $this->payroll->bonusByUserForMonth($this->manager->id, '2026-01'));
    }

    /** Чужие сделки в бонус сотрудника не попадают. */
    public function test_other_managers_deals_are_not_counted(): void
    {
        $other = User::factory()->create();
        $other->assignRole('manager');

        $this->wonDeal('2026-08-10');

        $this->assertSame(0.0, $this->payroll->bonusByUserForMonth($other->id, '2026-08'));
    }
}
