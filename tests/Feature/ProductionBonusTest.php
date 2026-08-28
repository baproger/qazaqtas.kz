<?php

namespace Tests\Feature;

use App\Models\BonusPayout;
use App\Models\Brigade;
use App\Models\Company;
use App\Models\EmployeeDebt;
use App\Models\Expense;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\BonusPayoutService;
use App\Services\PayrollService;
use App\Services\ProductionBonusService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Производство: бонус за выработку бригады.
 *
 * Правило владельца от 21.08.2026: бригадир получает 450 ₸ за м² и 35 ₸ за
 * штуку — за ВЕСЬ объём смены. Рабочий получает за свой объём по своей
 * ставке. Бонус даёт только наряд, подтверждённый мастером.
 */
class ProductionBonusTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private User $foreman;

    private User $worker;

    private Brigade $brigade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $company = Company::where('code', 'QT')->value('id');

        $this->director = User::factory()->create();
        $this->director->assignRole('director');
        $this->director->companies()->attach($company);

        $this->foreman = User::factory()->create(['name' => 'Бригадир']);
        $this->foreman->assignRole('foreman');
        $this->foreman->companies()->attach($company);

        $this->worker = User::factory()->create(['name' => 'Рабочий']);
        $this->worker->companies()->attach($company);

        $this->brigade = Brigade::create([
            'company_id' => $company, 'name' => 'Бригада 1',
            'foreman_id' => $this->foreman->id, 'is_active' => true,
        ]);
        $this->brigade->members()->attach($this->worker->id);
    }

    private function createOrder(array $lines, string $date = '2026-08-10'): WorkOrder
    {
        $this->actingAs($this->foreman)->post(route('production.orders.store'), [
            'brigade_id' => $this->brigade->id,
            'date' => $date,
            'product' => 'Брусчатка',
            'lines' => $lines,
        ])->assertSessionHasNoErrors();

        return WorkOrder::latest('id')->firstOrFail();
    }

    /** Ставки бригадира — 450 ₸/м² и 35 ₸/шт, из настроек. */
    public function test_foreman_rates_come_from_settings(): void
    {
        $rates = app(ProductionBonusService::class)->rates('foreman');

        $this->assertSame(450.0, $rates['m2']);
        $this->assertSame(35.0, $rates['pcs']);

        Setting::set('foreman_rate_m2', 500);
        $this->assertSame(500.0, app(ProductionBonusService::class)->rates('foreman')['m2']);
    }

    /** Бригадир получает за весь объём смены, отдельной строкой наряда. */
    public function test_foreman_is_paid_for_the_whole_shift(): void
    {
        $order = $this->createOrder([
            ['user_id' => $this->worker->id, 'qty_m2' => 30, 'qty_pcs' => 0],
            ['user_id' => $this->director->id, 'qty_m2' => 20, 'qty_pcs' => 100],
        ]);

        $foremanLine = $order->lines()->where('role', 'foreman')->firstOrFail();
        $this->assertSame($this->foreman->id, $foremanLine->user_id);
        $this->assertSame(50.0, (float) $foremanLine->qty_m2);
        $this->assertSame(100.0, (float) $foremanLine->qty_pcs);
        // 50 м² × 450 + 100 шт × 35 = 26 000 ₸.
        $this->assertSame(26000.0, (float) $foremanLine->amount);
    }

    /** Ставка рабочего по умолчанию нулевая: её задаёт владелец в настройках. */
    /**
     * Бонус смены целиком у бригадира (правило владельца от 28.08.2026).
     *
     * Строка рабочего остаётся, но с нулём: она держит ОБЪЁМ, по нему
     * считается выполнение плана. Убери её — и план перестал бы закрываться.
     * Кто из бригады сколько получит, решает бригадир вне системы.
     */
    public function test_the_whole_bonus_goes_to_the_foreman(): void
    {
        $order = $this->createOrder([['user_id' => $this->worker->id, 'qty_m2' => 10, 'qty_pcs' => 0]]);

        $worker = $order->lines()->where('user_id', $this->worker->id)->where('role', 'worker')->firstOrFail();
        $foreman = $order->lines()->where('role', 'foreman')->firstOrFail();

        $this->assertSame(0.0, (float) $worker->amount, 'Рабочему деньги не начисляются.');
        $this->assertSame(10.0, (float) $worker->qty_m2, 'Но объём его строка держит.');

        // Ставка бригадира по умолчанию 450 ₸/м²: 10 × 450 = 4 500.
        $this->assertSame(4500.0, (float) $foreman->amount);
        $this->assertSame(
            (float) $foreman->amount,
            round((float) $order->lines()->sum('amount'), 2),
            'Весь бонус наряда — на строке бригадира.',
        );
    }

    /** Ставка копируется в строку: поднятая цена не пересчитывает старые наряды. */
    public function test_rate_is_snapshotted_into_the_line(): void
    {
        $order = $this->createOrder([['user_id' => $this->worker->id, 'qty_m2' => 10, 'qty_pcs' => 0]]);
        $before = (float) $order->lines()->where('role', 'foreman')->value('amount');

        Setting::set('foreman_rate_m2', 900);

        $this->assertSame($before, (float) $order->fresh()->lines()->where('role', 'foreman')->value('amount'));
    }

    /** Неподтверждённый наряд бонуса не даёт. */
    public function test_unconfirmed_order_gives_no_bonus(): void
    {
        $this->createOrder([['user_id' => $this->worker->id, 'qty_m2' => 10, 'qty_pcs' => 0]]);

        $accruals = app(ProductionBonusService::class)
            ->accrualsByMonths([$this->foreman->id], ['2026-08']);

        $this->assertSame([], $accruals['2026-08']);
    }

    /** Подтверждение мастера превращает выработку в бонус. */
    public function test_confirmation_turns_output_into_a_bonus(): void
    {
        $order = $this->createOrder([['user_id' => $this->worker->id, 'qty_m2' => 10, 'qty_pcs' => 0]]);

        $this->actingAs($this->director)->patch(route('production.orders.confirm', $order->id))
            ->assertSessionHasNoErrors();

        $accruals = app(ProductionBonusService::class)
            ->accrualsByMonths([$this->foreman->id], ['2026-08']);

        $this->assertSame(4500.0, $accruals['2026-08'][$this->foreman->id]);
    }

    /** Наряд подтверждает мастер, а не сам бригадир. */
    public function test_foreman_cannot_confirm_his_own_order(): void
    {
        $order = $this->createOrder([['user_id' => $this->worker->id, 'qty_m2' => 10, 'qty_pcs' => 0]]);

        $this->actingAs($this->foreman)->patch(route('production.orders.confirm', $order->id))
            ->assertForbidden();

        $this->assertSame('draft', $order->fresh()->status);
    }

    /** Чужую бригаду бригадир не заполняет. */
    public function test_foreman_cannot_file_for_another_brigade(): void
    {
        $other = Brigade::create(['name' => 'Бригада 2', 'foreman_id' => $this->director->id, 'is_active' => true]);

        $this->actingAs($this->foreman)->post(route('production.orders.store'), [
            'brigade_id' => $other->id, 'date' => '2026-08-10',
            'lines' => [['user_id' => $this->worker->id, 'qty_m2' => 5]],
        ])->assertForbidden();
    }

    /** Бонус производства попадает в общую годовую копилку сотрудника. */
    public function test_production_bonus_lands_in_the_year_page(): void
    {
        $order = $this->createOrder([['user_id' => $this->worker->id, 'qty_m2' => 10, 'qty_pcs' => 0]]);
        $this->actingAs($this->director)->patch(route('production.orders.confirm', $order->id));

        $year = app(BonusPayoutService::class)
            ->yearFor(User::whereKey($this->foreman->id)->get(), 2026);

        $row = collect($year)->firstWhere('uid', $this->foreman->id);
        $this->assertSame(4500.0, round((float) $row['accrued'], 2));
        $this->assertSame(4500.0, round((float) $row['left'], 2), 'Бонус ещё не выплачен — числится за компанией.');

        $august = collect($row['months'])->firstWhere('month', '2026-08');
        $this->assertSame(4500.0, round((float) $august['accrued'], 2));
    }

    /**
     * Выработка идёт и в ведомость зарплаты.
     *
     * Бригадир зарабатывает объёмом, а не процентом со сделок: без этого его
     * строка в ЗП показывала бы «только оклад» и расходилась со страницей
     * «Бонусы» — две правды о том, сколько человеку должны.
     */
    public function test_payroll_row_includes_production_bonus(): void
    {
        $order = $this->createOrder([['user_id' => $this->worker->id, 'qty_m2' => 10, 'qty_pcs' => 0]]);
        $this->actingAs($this->director)->patch(route('production.orders.confirm', $order->id));

        $this->actingAs($this->director)->get(route('payroll.index', ['month' => '2026-08']))
            ->assertInertia(fn ($page) => $page
                ->where('rows', function ($rows) {
                    $row = collect($rows)->firstWhere('uid', $this->foreman->id);

                    return round((float) $row['bonus_production'], 2) === 4500.0
                        && round((float) $row['bonus'], 2) === 4500.0
                        && round((float) $row['payout'], 2) === round((float) $row['base'] + 4500, 2);
                }));
    }

    /** Долг гасится и из бонуса за выработку — деньги для сотрудника одни. */
    public function test_debt_is_charged_from_the_production_bonus(): void
    {
        $order = $this->createOrder([['user_id' => $this->worker->id, 'qty_m2' => 10, 'qty_pcs' => 0]]);
        $this->actingAs($this->director)->patch(route('production.orders.confirm', $order->id));

        $debt = EmployeeDebt::create([
            'user_id' => $this->foreman->id, 'amount' => 20000,
            'monthly_payment' => 3000, 'payment_method' => 'cash',
        ]);

        $this->artisan('debts:charge', ['--month' => '2026-08'])->assertSuccessful();

        $this->assertSame(round(20000 - 3000, 2), $debt->fresh()->balance());
    }

    /**
     * Двойной клик по «Выплатить» не выдаёт бонус дважды.
     *
     * Раньше «сколько уже выплачено» читалось ДО транзакции: два запроса
     * успевали увидеть нули и создавали два расхода на одну и ту же сумму —
     * деньги уходили из кассы дважды.
     */
    public function test_paying_the_same_month_twice_pays_only_once(): void
    {
        $order = $this->createOrder([['user_id' => $this->worker->id, 'qty_m2' => 10, 'qty_pcs' => 0]]);
        $this->actingAs($this->director)->patch(route('production.orders.confirm', $order->id));

        $payouts = app(BonusPayoutService::class);
        $first = $payouts->pay($this->foreman, ['2026-08'], 'cash', $this->director);
        $second = $payouts->pay($this->foreman, ['2026-08'], 'cash', $this->director);

        $this->assertSame(4500.0, $first['paid']);
        $this->assertSame(0.0, $second['paid'], 'Второй раз платить нечего — бонус уже выдан.');
        $this->assertSame(4500.0, round((float) BonusPayout::sum('amount'), 2));
        $this->assertSame(1, Expense::where('employee_payout', 'bonus')->count());
    }

    /**
     * Рабочий без оклада и без сделок остаётся в ведомости.
     *
     * Его заработок — только объём. Пока строк ведомости не было, бонус за
     * смены не попадал ни в «К выплате», ни в ЗП компании.
     */
    /** Заработавший ТОЛЬКО объёмом (без оклада) обязан быть в ведомости. */
    public function test_someone_earning_only_by_volume_stays_in_the_payroll(): void
    {
        $this->foreman->update(['salary' => 0]);

        $order = $this->createOrder([['user_id' => $this->worker->id, 'qty_m2' => 10, 'qty_pcs' => 0]]);
        $this->actingAs($this->director)->patch(route('production.orders.confirm', $order->id));

        $row = app(PayrollService::class)->perUser()->firstWhere('uid', $this->foreman->id);

        $this->assertNotNull($row, 'Заработавший объёмом обязан быть в ведомости.');
        $this->assertSame(4500.0, round((float) $row['bonus_production'], 2));
        $this->assertSame(4500.0, round((float) $row['payout'], 2));
    }

    /**
     * Переплата видна, а не спрятана.
     *
     * Наряд удалили уже после выплаты — начисление упало ниже выплаченного.
     * Раньше остаток обрезался до нуля, и деньги, выданные сверх, исчезали
     * со страницы вместе с вопросом «кто кому должен».
     */
    public function test_an_overpayment_stays_visible(): void
    {
        $order = $this->createOrder([['user_id' => $this->worker->id, 'qty_m2' => 10, 'qty_pcs' => 0]]);
        $this->actingAs($this->director)->patch(route('production.orders.confirm', $order->id));
        app(BonusPayoutService::class)->pay($this->foreman, ['2026-08'], 'cash', $this->director);

        $this->actingAs($this->director)->delete(route('production.orders.destroy', $order->id))
            ->assertSessionHasNoErrors();

        $row = collect(app(BonusPayoutService::class)
            ->yearFor(User::whereKey($this->foreman->id)->get(), 2026))->first();

        $this->assertSame(0.0, round((float) $row['accrued'], 2));
        $this->assertSame(4500.0, round((float) $row['paid'], 2));
        $this->assertSame(0.0, round((float) $row['left'], 2));
        $this->assertSame(4500.0, round((float) $row['overpaid'], 2), 'Переплату обязано быть видно.');
    }

    /** Повторная отправка формы не создаёт второй наряд за ту же смену. */
    public function test_the_same_shift_is_not_filed_twice(): void
    {
        $lines = [['user_id' => $this->worker->id, 'qty_m2' => 10, 'qty_pcs' => 0]];
        $this->createOrder($lines);

        $this->actingAs($this->foreman)->post(route('production.orders.store'), [
            'brigade_id' => $this->brigade->id,
            'date' => '2026-08-10',
            'product' => 'Брусчатка',
            'lines' => $lines,
        ])->assertSessionHasErrors('lines');

        $this->assertSame(1, WorkOrder::count());
    }

    /** Наряд чужой фирмы не подтвердить: бонус ушёл бы из чужой кассы. */
    public function test_an_order_of_another_company_cannot_be_confirmed(): void
    {
        $other = Company::create(['name' => 'Вторая фирма', 'code' => 'XX']);
        $order = $this->createOrder([['user_id' => $this->worker->id, 'qty_m2' => 5, 'qty_pcs' => 0]]);
        $order->update(['company_id' => $other->id]);

        $this->actingAs($this->director)->patch(route('production.orders.confirm', $order->id))
            ->assertForbidden();

        $this->assertSame('draft', $order->fresh()->status);
    }

    /**
     * Кто сколько сделал — в карточке бригады, итог месяца — на «Всех нарядах».
     *
     * Разбивка по людям живёт в ОДНОМ месте: держи её и там, и тут — однажды
     * две копии одной суммы разойдутся.
     */
    public function test_output_per_person_lives_in_the_brigade_card(): void
    {
        $order = $this->createOrder([['user_id' => $this->worker->id, 'qty_m2' => 12, 'qty_pcs' => 0]]);
        $this->actingAs($this->director)->patch(route('production.orders.confirm', $order->id));

        $this->actingAs($this->director)->get(route('production.index', ['month' => '2026-08']))
            ->assertInertia(fn ($page) => $page
                ->component('Production/Index')
                ->where('totals.m2', fn ($m2) => (float) $m2 === 12.0)
                ->missing('byPerson'));

        $this->actingAs($this->director)
            ->get(route('production.brigade', ['brigade' => $this->brigade->id, 'month' => '2026-08']))
            ->assertInertia(fn ($page) => $page
                ->component('Production/Brigade')
                ->where('byPerson', fn ($rows) => collect($rows)->firstWhere('name', 'Бригадир')['amount'] == 5400.0)
                ->etc());
    }
}
