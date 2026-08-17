<?php

namespace Tests\Feature;

use App\Models\CashReceipt;
use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Debt;
use App\Models\Expense;
use App\Models\PayrollAdjustment;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\PayrollService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Находки карты кодовой базы (17.08.2026) — закреплены тестами, чтобы не
 * вернулись. Каждый тест назван проблемой, которую он держит закрытой.
 */
class CodebaseFindingsTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->companyId = (int) Company::where('code', 'QT')->value('id');
        $this->accountant = $this->staff('financist');
    }

    private function staff(string $role, ?int $companyId = null): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->companies()->attach($companyId ?? $this->companyId);

        return $user;
    }

    private function deal(): Deal
    {
        return Deal::create([
            'company_id' => $this->companyId,
            'number' => 'QT-'.uniqid(), 'name' => 'Сделка', 'company_name' => 'Клиент',
            'budget' => 100000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->value('id'),
            'responsible_user_id' => $this->accountant->id,
        ]);
    }

    /**
     * Удаление сделки не двигает кассу: деньги по ней уже ушли, а удаление
     * мягкое — расход никуда не делся.
     */
    public function test_deleting_a_deal_does_not_move_the_cash(): void
    {
        $deal = $this->deal();
        Expense::create([
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'amount' => 25000, 'date' => now()->toDateString(),
            'status' => 'confirmed', 'payment_method' => 'cash',
            'responsible_user_id' => $this->accountant->id,
        ]);

        $before = app(FinanceService::class)->companyBalances($this->companyId)['cash'];
        $deal->delete();
        $after = app(FinanceService::class)->companyBalances($this->companyId)['cash'];

        $this->assertSame(round($before, 2), round($after, 2), 'Удаление сделки меняет остаток кассы.');
    }

    /** Удаление аванса — движение денег: СЕО и директор узнают о нём. */
    public function test_deleting_an_advance_notifies_leadership(): void
    {
        $director = $this->staff('director');
        $worker = $this->staff('employee');

        $this->actingAs($this->accountant)->post(route('payroll.adjustments.store'), [
            'user_id' => $worker->id, 'type' => 'advance', 'amount' => 30000,
            'date' => now()->toDateString(), 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();

        $adjustment = PayrollAdjustment::firstOrFail();
        $this->actingAs($this->accountant)->delete(route('payroll.adjustments.destroy', $adjustment->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $director->notifications()
            ->where('type', \App\Notifications\FinanceRecordDeleted::class)->count());
    }

    /** Поступление чужой фирмы не удалить и в режиме «Все компании». */
    public function test_receipt_of_another_company_cannot_be_deleted(): void
    {
        $other = Company::create(['name' => 'Другая', 'code' => 'OTH', 'is_active' => true]);
        $receipt = CashReceipt::create([
            'company_id' => $other->id, 'amount' => 10000, 'method' => 'cash',
            'source' => 'чужое', 'date' => now()->toDateString(),
            'created_by' => $this->accountant->id,
        ]);

        // «Все компании»: выбранной фирмы нет — раньше проверка отключалась.
        $this->actingAs($this->accountant)
            ->delete(route('finance.receipts.destroy', $receipt->id))
            ->assertForbidden();

        $this->assertNotNull(CashReceipt::find($receipt->id));
    }

    /** То же для задолженностей. */
    public function test_debt_of_another_company_cannot_be_deleted(): void
    {
        $other = Company::create(['name' => 'Другая-2', 'code' => 'OT2', 'is_active' => true]);
        $debt = Debt::create([
            'company_id' => $other->id, 'type' => 'receivable', 'counterparty' => 'Чужой',
            'amount' => 50000, 'date' => now()->toDateString(),
        ]);

        $this->actingAs($this->accountant)->delete(route('finance.debts.destroy', $debt->id))
            ->assertForbidden();
    }

    /** Оклад сотруднику чужой фирмы не поставить. */
    public function test_salary_of_another_company_employee_is_refused(): void
    {
        $other = Company::create(['name' => 'Другая-3', 'code' => 'OT3', 'is_active' => true]);
        $stranger = $this->staff('employee', $other->id);

        $this->actingAs($this->accountant)->patch(route('payroll.salary', $stranger->id), ['salary' => 500000])
            ->assertForbidden();

        $this->assertSame(0.0, (float) $stranger->fresh()->salary);
    }

    /** Долг сотруднику чужой фирмы не выдать. */
    public function test_debt_to_another_company_employee_is_refused(): void
    {
        $other = Company::create(['name' => 'Другая-4', 'code' => 'OT4', 'is_active' => true]);
        $stranger = $this->staff('employee', $other->id);

        $this->actingAs($this->accountant)->post(route('payroll.debts.store'), [
            'user_id' => $stranger->id, 'amount' => 50000,
            'monthly_payment' => 5000, 'payment_method' => 'cash',
        ])->assertForbidden();
    }

    /**
     * Воронка без «Акта»: блок «на подходе» пуст, а не заполнен случайным
     * этапом по позиции.
     */
    public function test_pending_block_is_empty_without_act_stage(): void
    {
        DealStage::whereIn('stage_type', ['act', 'esf'])->update(['stage_type' => null]);

        $manager = $this->staff('manager');
        $deal = $this->deal();
        $deal->update([
            'responsible_user_id' => $manager->id,
            // Предпоследний этап воронки — тот самый, что подставлялся раньше.
            'deal_stage_id' => DealStage::orderByDesc('order')->skip(1)->value('id'),
        ]);

        $rows = app(PayrollService::class)->dealBreakdown()->get($manager->id) ?? collect();

        $this->assertSame(0, $rows->count(), 'Этап по позиции снова попал в «на подходе».');
    }

    /** Показываемый % бонуса совпадает с тем, по которому платят. */
    public function test_displayed_bonus_rate_respects_the_personal_percent(): void
    {
        $manager = $this->staff('manager');
        $manager->update(['bonus_percent' => 3]);
        PayrollService::forgetBonusPercents();

        $deal = $this->deal();
        $deal->update([
            'responsible_user_id' => $manager->id,
            'deal_stage_id' => DealStage::where('is_won', true)->value('id'),
        ]);

        $row = app(PayrollService::class)->dealBreakdown()->get($manager->id)?->first();

        $this->assertNotNull($row, 'Сделка должна попасть в разбивку.');
        $this->assertSame(3.0, (float) $row['bonus_rate'], 'В строке показана авто-ступень вместо личного процента.');
    }
}
