<?php

namespace Tests\Feature;

use App\Models\Company;
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
 * Плитка «Бонус за месяц» в ведомости ЗП.
 *
 * Цифра приходит из bonusByUserForMonth (шаг 2) — второго расчёта в системе
 * быть не должно, иначе ведомость и удержание долгов разойдутся.
 */
class PayrollMonthBonusTest extends TestCase
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

        $this->manager = User::factory()->create(['salary' => 150000]);
        $this->manager->assignRole('manager');
        $this->manager->companies()->attach(Company::where('code', 'QT')->value('id'));
    }

    private function wonDeal(string $contractDate, float $budget = 1000000): Deal
    {
        $deal = Deal::create([
            'company_id' => Company::where('code', 'QT')->value('id'),
            'number' => 'QT-'.uniqid(), 'name' => 'Сделка', 'company_name' => 'Клиент',
            'budget' => $budget, 'status' => 'active', 'contract_date' => $contractDate,
            'deal_stage_id' => DealStage::where('is_won', true)->value('id'),
            'responsible_user_id' => $this->manager->id,
        ]);

        $invoice = Invoice::create([
            'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id,
            'number' => 'INV-'.uniqid(), 'amount' => $budget, 'status' => 'sent',
        ]);
        Payment::create([
            'invoice_id' => $invoice->id, 'amount' => $budget,
            'payment_date' => $contractDate, 'payment_method' => 'bank',
        ]);

        return $deal;
    }

    /** Строка ведомости повторяет метод шага 2 копейка в копейку. */
    public function test_row_bonus_month_equals_the_service_method(): void
    {
        $this->wonDeal('2026-07-10');
        $this->wonDeal('2026-08-10');

        $expected = app(PayrollService::class)->bonusByUserForMonth($this->manager->id, '2026-07');
        $this->assertGreaterThan(0, $expected);

        $this->actingAs($this->accountant)->get(route('payroll.index', ['month' => '2026-07']))
            ->assertInertia(fn ($page) => $page
                ->where('rows', fn ($rows) => (float) collect($rows)->firstWhere('uid', $this->manager->id)['bonus_month'] === $expected)
                // JSON целые числа отдаёт целыми — сравниваем в том же виде.
                ->where('totals.bonus_month', $expected == (int) $expected ? (int) $expected : $expected));
    }

    /** Плитка месяца не меняет «К выплате»: бонус там — за всё время. */
    public function test_month_tile_does_not_change_the_payout(): void
    {
        $this->wonDeal('2026-07-10');

        $this->actingAs($this->accountant)->get(route('payroll.index', ['month' => '2026-07']))
            ->assertInertia(fn ($page) => $page
                ->where('rows', function ($rows) {
                    $row = collect($rows)->firstWhere('uid', $this->manager->id);

                    // payout = оклад (или почасовая база) + бонус за всё время.
                    return round((float) $row['payout'], 2) === round((float) $row['base'] + (float) $row['bonus'], 2)
                        && (float) $row['bonus_month'] > 0;
                }));
    }

    /** Пустой месяц — ноль, а не бонус соседнего месяца. */
    public function test_month_without_deals_is_zero(): void
    {
        $this->wonDeal('2026-07-10');

        $this->actingAs($this->accountant)->get(route('payroll.index', ['month' => '2026-09']))
            ->assertInertia(fn ($page) => $page->where('totals.bonus_month', 0));
    }

    /** Батч-метод даёт то же, что и поштучный вызов. */
    public function test_batched_bonus_matches_the_single_call(): void
    {
        $other = User::factory()->create();
        $other->assignRole('manager');
        $this->wonDeal('2026-07-10');

        $payroll = app(PayrollService::class);
        $batch = $payroll->bonusByUsersForMonth([$this->manager->id, $other->id], '2026-07');

        $this->assertSame($payroll->bonusByUserForMonth($this->manager->id, '2026-07'), $batch[$this->manager->id]);
        $this->assertArrayNotHasKey($other->id, $batch, 'Без сделок сотрудник в выдаче не появляется.');
        $this->assertSame(0.0, $payroll->bonusByUserForMonth($other->id, '2026-07'));
    }
}
