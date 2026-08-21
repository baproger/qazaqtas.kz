<?php

namespace Tests\Feature;

use App\Models\Brigade;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\BonusPayoutService;
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
    public function test_worker_rate_is_set_by_the_owner(): void
    {
        Setting::set('worker_rate_m2', 300);

        $order = $this->createOrder([['user_id' => $this->worker->id, 'qty_m2' => 10, 'qty_pcs' => 0]]);
        $line = $order->lines()->where('user_id', $this->worker->id)->where('role', 'worker')->firstOrFail();

        $this->assertSame(3000.0, (float) $line->amount);
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

    /** Страница производства показывает, кто сколько сделал. */
    public function test_page_shows_output_per_person(): void
    {
        $order = $this->createOrder([['user_id' => $this->worker->id, 'qty_m2' => 12, 'qty_pcs' => 0]]);
        $this->actingAs($this->director)->patch(route('production.orders.confirm', $order->id));

        $this->actingAs($this->director)->get(route('production.index', ['month' => '2026-08']))
            ->assertInertia(fn ($page) => $page
                ->component('Production/Index')
                ->where('totals.m2', fn ($m2) => (float) $m2 === 12.0)
                ->where('byPerson', fn ($rows) => collect($rows)->firstWhere('name', 'Бригадир')['amount'] == 5400.0));
    }
}
