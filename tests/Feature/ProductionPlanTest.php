<?php

namespace Tests\Feature;

use App\Models\Brigade;
use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Product;
use App\Models\ProductionPlan;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\ProductionPlanQueued;
use App\Services\ProductionProgressService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * План — факт: задание цеху на месяц.
 *
 * План ставит директор, выполняет бригада, подтверждает директор ИЛИ финансист
 * — достаточно одного. Выполнение считается по нарядам этого плана: второго
 * счётчика нет, иначе страница плана и «Наряды по сменам» разошлись бы.
 */
class ProductionPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private User $financist;

    private User $master;

    private User $foreman;

    private Brigade $brigade;

    private Product $tile;

    private int $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->company = Company::where('code', 'QT')->value('id');

        foreach (['director', 'financist'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $user->companies()->attach($this->company);
            $this->{$role} = $user;
        }

        // Смену принимает начальник производства (правило от 28.08.2026).
        $this->master = User::factory()->create(['name' => 'Начальник цеха']);
        $this->master->assignRole('production_head');
        $this->master->companies()->attach($this->company);

        $this->foreman = User::factory()->create(['name' => 'Асхат Бекболат']);
        $this->foreman->assignRole('foreman');
        $this->foreman->companies()->attach($this->company);

        $this->brigade = Brigade::create([
            'company_id' => $this->company, 'name' => 'Бригада №1',
            'workshop' => 'Шымкент', 'foreman_id' => $this->foreman->id, 'is_active' => true,
        ]);
        $this->brigade->members()->attach(User::factory()->create(['name' => 'Ержан'])->id);

        $this->tile = Product::create([
            'name' => 'Брусчатка «Классика» 60мм', 'unit' => 'м²',
            'price' => 9000, 'is_active' => true, 'is_service' => false,
        ]);
    }

    private function plan(array $extra = []): ProductionPlan
    {
        return ProductionPlan::create(array_merge([
            'company_id' => $this->company,
            'period_month' => now()->startOfMonth()->toDateString(),
            'brigade_id' => $this->brigade->id,
            'product_id' => $this->tile->id,
            'plan_qty' => 1000,
            'unit' => 'м²',
            'status' => 'active',
            'created_by' => $this->director->id,
        ], $extra));
    }

    /** План ставит директор. */
    public function test_the_director_sets_the_plan(): void
    {
        $this->actingAs($this->director)->post(route('production.plans.store'), [
            'period_month' => now()->format('Y-m'),
            'brigade_id' => $this->brigade->id,
            'product_id' => $this->tile->id,
            'plan_qty' => 1000,
        ])->assertSessionHasNoErrors();

        $plan = ProductionPlan::first();
        $this->assertSame(1000.0, (float) $plan->plan_qty);
        $this->assertSame('м²', $plan->unit, 'Единица — снимок каталога');
    }

    /** Бригадир и финансист план не ставят: это задание, а не отчёт. */
    public function test_only_leadership_sets_the_plan(): void
    {
        foreach ([$this->foreman, $this->financist] as $user) {
            $this->actingAs($user)->post(route('production.plans.store'), [
                'period_month' => now()->format('Y-m'),
                'brigade_id' => $this->brigade->id,
                'product_id' => $this->tile->id,
                'plan_qty' => 500,
            ])->assertForbidden();
        }

        $this->assertSame(0, ProductionPlan::count());
    }

    /** Дубль плана удвоил бы и задание, и процент выполнения. */
    public function test_a_duplicate_plan_is_rejected(): void
    {
        $this->plan();

        $this->actingAs($this->director)->post(route('production.plans.store'), [
            'period_month' => now()->format('Y-m'),
            'brigade_id' => $this->brigade->id,
            'product_id' => $this->tile->id,
            'plan_qty' => 200,
        ])->assertSessionHasErrors('product_id');

        $this->assertSame(1, ProductionPlan::count());
    }

    /** Бригадир видит поставленный ему план. */
    public function test_the_foreman_sees_his_plan(): void
    {
        $this->plan();

        // Чужая бригада — чужое задание.
        $strangerBrigade = Brigade::create([
            'company_id' => $this->company, 'name' => 'Чужая', 'workshop' => 'Шымкент',
            'foreman_id' => User::factory()->create()->id, 'is_active' => true,
        ]);
        ProductionPlan::create([
            'company_id' => $this->company, 'period_month' => now()->startOfMonth()->toDateString(),
            'brigade_id' => $strangerBrigade->id, 'product_id' => $this->tile->id,
            'plan_qty' => 400, 'unit' => 'м²', 'status' => 'active',
        ]);

        $this->actingAs($this->foreman)->get(route('production.plans.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Production/Plans')
                ->where('isForeman', true)
                ->where('canPlan', false)
                ->where('plans', fn ($plans) => collect($plans)->pluck('brigade')->all() === ['Бригада №1'])
                ->etc());
    }

    /**
     * Выполнение: бригадир записал — ждёт, подтвердили — выполнено.
     *
     * До подтверждения объём в «сделано» не идёт: иначе выработку можно было
     * бы приписать себе и получить за неё бонус.
     */
    public function test_output_waits_for_confirmation(): void
    {
        $plan = $this->plan();

        $this->actingAs($this->foreman)
            ->post(route('production.plans.output', $plan->id), ['qty' => 300])
            ->assertSessionHasNoErrors();

        $stats = app(ProductionProgressService::class)->forPlans([$plan]);
        $this->assertSame(0.0, $stats[$plan->id]['done']);
        $this->assertSame(300.0, $stats[$plan->id]['pending']);

        $order = WorkOrder::firstWhere('production_plan_id', $plan->id);
        $this->assertSame('draft', $order->status);
        $this->assertSame('Брусчатка «Классика» 60мм', $order->product);

        $this->actingAs($this->master)->patch(route('production.orders.confirm', $order->id))
            ->assertSessionHasNoErrors();

        $stats = app(ProductionProgressService::class)->forPlans([$plan->fresh()]);
        $this->assertSame(300.0, $stats[$plan->id]['done']);
        $this->assertSame(700.0, $stats[$plan->id]['left']);
        $this->assertSame(30.0, $stats[$plan->id]['percent']);
    }

    /** Подтверждает и финансист, и директор — достаточно одного. */
    /**
     * Достаточно одного из двух: директор ИЛИ начальник производства.
     * Двойная подпись останавливала бы цех, пока один в отъезде.
     */
    public function test_either_the_director_or_the_production_head_confirms(): void
    {
        foreach ([$this->director, $this->master] as $confirmer) {
            $plan = $this->plan(['product_id' => Product::create([
                'name' => 'Товар '.$confirmer->id, 'unit' => 'м²', 'price' => 1, 'is_active' => true, 'is_service' => false,
            ])->id]);

            $this->actingAs($this->foreman)->post(route('production.plans.output', $plan->id), ['qty' => 10]);
            $order = WorkOrder::where('production_plan_id', $plan->id)->firstOrFail();

            $this->actingAs($confirmer)->patch(route('production.orders.confirm', $order->id))
                ->assertSessionHasNoErrors();

            $this->assertSame('confirmed', $order->fresh()->status);
        }
    }

    /** Свою выработку бригадир не подтверждает. */
    public function test_the_foreman_cannot_confirm_his_own_output(): void
    {
        $plan = $this->plan();
        $this->actingAs($this->foreman)->post(route('production.plans.output', $plan->id), ['qty' => 100]);
        $order = WorkOrder::firstWhere('production_plan_id', $plan->id);

        $this->actingAs($this->foreman)->patch(route('production.orders.confirm', $order->id))->assertForbidden();
        $this->assertSame('draft', $order->fresh()->status);
    }

    /** Отклонение возвращает запись бригадиру с причиной. */
    public function test_a_rejected_order_carries_the_reason(): void
    {
        $plan = $this->plan();
        $this->actingAs($this->foreman)->post(route('production.plans.output', $plan->id), ['qty' => 100]);
        $order = WorkOrder::firstWhere('production_plan_id', $plan->id);

        $this->actingAs($this->master)
            ->patch(route('production.orders.reject', $order->id), ['reason' => 'Нет фото партии'])
            ->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('rejected', $order->status);
        $this->assertSame('Нет фото партии', $order->reject_reason);

        // Отклонённое не считается ни сделанным, ни ожидающим подтверждения.
        $stats = app(ProductionProgressService::class)->forPlans([$plan]);
        $this->assertSame(0.0, $stats[$plan->id]['done']);
    }

    /**
     * Бонус бригадира считается по ставке ПЛАНА и замораживается в строке.
     *
     * Подняли цену за метр в следующем месяце — прошлые смены пересчитываться
     * не должны, иначе выплаченная зарплата меняется задним числом.
     */
    public function test_the_plan_rate_freezes_in_the_line(): void
    {
        $plan = $this->plan(['bonus_rate' => 600]);

        $this->actingAs($this->foreman)->post(route('production.plans.output', $plan->id), ['qty' => 100]);
        $order = WorkOrder::firstWhere('production_plan_id', $plan->id);
        $foremanLine = $order->lines()->where('role', 'foreman')->firstOrFail();

        $this->assertSame(600.0, (float) $foremanLine->rate_m2);
        $this->assertSame(60000.0, (float) $foremanLine->amount);

        $plan->update(['bonus_rate' => 900]);
        $this->assertSame(600.0, (float) $foremanLine->fresh()->rate_m2);
    }

    /** План с подтверждённой выработкой не правят и не удаляют. */
    public function test_a_plan_with_confirmed_output_is_frozen(): void
    {
        $plan = $this->plan();
        $this->actingAs($this->foreman)->post(route('production.plans.output', $plan->id), ['qty' => 100]);
        $order = WorkOrder::firstWhere('production_plan_id', $plan->id);
        $this->actingAs($this->director)->patch(route('production.orders.confirm', $order->id));

        $this->actingAs($this->director)
            ->patch(route('production.plans.update', $plan->id), ['plan_qty' => 50])
            ->assertStatus(422);

        $this->actingAs($this->director)
            ->delete(route('production.plans.destroy', $plan->id))
            ->assertStatus(422);

        $this->assertSame(1000.0, (float) $plan->fresh()->plan_qty);
    }

    /** Карточка бригады: планы, смены и кто сколько заработал. */
    public function test_the_brigade_page_shows_the_month(): void
    {
        $plan = $this->plan();
        $this->actingAs($this->foreman)->post(route('production.plans.output', $plan->id), ['qty' => 250]);
        $order = WorkOrder::firstWhere('production_plan_id', $plan->id);
        $this->actingAs($this->director)->patch(route('production.orders.confirm', $order->id));

        $this->actingAs($this->director)->get(route('production.brigade', $this->brigade->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Production/Brigade')
                ->where('brigade.name', 'Бригада №1')
                ->where('brigade.members', fn ($m) => collect($m)->pluck('name')->contains('Ержан'))
                ->where('plans.0.done', fn ($v) => (float) $v === 250.0)
                ->where('orders', fn ($o) => count($o) === 1)
                ->where('byPerson', fn ($p) => collect($p)->sum('amount') > 0)
                ->etc());
    }

    /** Чужую бригаду бригадир не открывает. */
    public function test_a_foreman_cannot_open_another_brigade(): void
    {
        $stranger = Brigade::create([
            'company_id' => $this->company, 'name' => 'Чужая', 'workshop' => 'Шымкент',
            'foreman_id' => User::factory()->create()->id, 'is_active' => true,
        ]);

        $this->actingAs($this->foreman)->get(route('production.brigade', $stranger->id))->assertForbidden();
        $this->actingAs($this->foreman)->get(route('production.brigade', $this->brigade->id))->assertOk();
    }

    /**
     * Итог месяца не складывает метры со штуками.
     *
     * «План 2100» из 1000 м² плитки и 1100 штук вазонов — величина, которой
     * не существует. Считаем раздельно по метрике.
     */
    public function test_the_month_total_keeps_units_apart(): void
    {
        $this->plan(['plan_qty' => 1000]);                       // м²
        $vase = Product::create(['name' => 'Вазон «Чаша»', 'unit' => 'штук', 'price' => 60000, 'is_active' => true, 'is_service' => false]);
        $this->plan(['product_id' => $vase->id, 'unit' => 'штук', 'plan_qty' => 100]);

        $this->actingAs($this->director)->get(route('production.plans.index'))
            ->assertInertia(fn ($page) => $page
                ->where('summary.measures', function ($measures) {
                    $rows = collect($measures)->keyBy('measure');

                    return count($measures) === 2
                        && (float) $rows['m2']['plan'] === 1000.0
                        && (float) $rows['pcs']['plan'] === 100.0;
                })
                ->etc());
    }

    /** По чужому плану бригадир выработку не пишет. */
    public function test_output_only_on_your_own_plan(): void
    {
        $strangerBrigade = Brigade::create([
            'company_id' => $this->company, 'name' => 'Чужая', 'workshop' => 'Шымкент',
            'foreman_id' => User::factory()->create()->id, 'is_active' => true,
        ]);
        $plan = $this->plan(['brigade_id' => $strangerBrigade->id]);

        $this->actingAs($this->foreman)
            ->post(route('production.plans.output', $plan->id), ['qty' => 50])
            ->assertForbidden();

        $this->assertSame(0, WorkOrder::count());
    }

    /**
     * Планы делятся на «в работе» и «выполнено», и подытоги СХОДЯТСЯ с итогом.
     *
     * Сумма блоков обязана равняться итогу месяца: разойдись они, страница
     * начала бы спорить сама с собой, и понять, какая цифра верная, было бы
     * нечем. Признак «выполнен» считает сервер — посчитай его в браузере, и
     * деление на блоки разъедется с подытогами.
     */
    public function test_the_page_splits_plans_and_the_parts_add_up(): void
    {
        // Закрытый план: 500 из 500.
        $closed = $this->plan(['plan_qty' => 500]);
        $this->actingAs($this->foreman)->post(route('production.plans.output', $closed->id), ['qty' => 500]);
        $this->actingAs($this->master)->patch(route('production.orders.confirm', WorkOrder::latest('id')->value('id')));

        // Начатый план: 200 из 1000.
        $open = $this->plan(['product_id' => Product::create([
            'name' => 'Вазон', 'unit' => 'шт', 'price' => 1000, 'is_active' => true,
        ])->id, 'plan_qty' => 1000, 'unit' => 'шт']);
        $this->actingAs($this->foreman)->post(route('production.plans.output', $open->id), ['qty' => 200]);
        $this->actingAs($this->master)->patch(route('production.orders.confirm', WorkOrder::latest('id')->value('id')));

        $this->actingAs($this->director)->get(route('production.plans.index'))
            ->assertInertia(function ($page) {
                $props = $page->toArray()['props'];
                $summary = $props['summary'];

                $this->assertSame(1, $summary['done']['count'], 'Закрытый план — в «Выполнено».');
                $this->assertSame(1, $summary['active']['count'], 'Начатый — в «В работе».');

                // Бонус: части складываются в целое.
                $this->assertEqualsWithDelta(
                    $summary['bonus'],
                    $summary['active']['bonus'] + $summary['done']['bonus'],
                    0.01,
                    'Сумма бонусов по блокам не сошлась с итогом месяца.',
                );

                // И по каждой метрике отдельно: метры и штуки не смешиваются.
                foreach ($summary['measures'] as $total) {
                    $parts = 0.0;
                    foreach (['active', 'done'] as $section) {
                        foreach ($summary[$section]['measures'] as $row) {
                            if ($row['measure'] === $total['measure']) {
                                $parts += (float) $row['done'];
                            }
                        }
                    }
                    $this->assertEqualsWithDelta((float) $total['done'], $parts, 0.01,
                        "Метрика {$total['measure']}: блоки не сошлись с итогом.");
                }

                // Выработка бригад: колонка «по плану» = бонус планов месяца.
                $planAmount = collect($props['brigadeOutput'])->sum('plan_amount');
                $this->assertEqualsWithDelta($summary['bonus'], $planAmount, 0.01,
                    'Выработка «по плану» разошлась с бонусом планов.');
            });
    }

    /** Неподтверждённая смена в выработку не идёт: это ещё не деньги. */
    public function test_an_unconfirmed_shift_stays_out_of_the_output(): void
    {
        $plan = $this->plan(['plan_qty' => 500]);
        $this->actingAs($this->foreman)->post(route('production.plans.output', $plan->id), ['qty' => 100]);

        $this->actingAs($this->director)->get(route('production.plans.index'))
            ->assertInertia(fn ($page) => $page
                ->where('brigadeOutput', fn ($rows) => collect($rows)->sum('amount') == 0)
                ->etc());
    }

    /**
     * Финансист смену больше не принимает (правило от 28.08.2026).
     *
     * Раньше он был вторым мастером «чтобы цех не стоял». Теперь принимает
     * тот, кто отвечает за цех, а не тот, кто платит: подпись под объёмом
     * должна стоить знания, что этот объём действительно сделан.
     */
    public function test_the_financist_no_longer_confirms_a_shift(): void
    {
        $plan = $this->plan();
        $this->actingAs($this->foreman)->post(route('production.plans.output', $plan->id), ['qty' => 100]);
        $order = WorkOrder::firstWhere('production_plan_id', $plan->id);

        $this->actingAs($this->financist)
            ->patch(route('production.orders.confirm', $order->id))
            ->assertForbidden();

        $this->assertSame('draft', $order->fresh()->status);
    }

    /**
     * Нехватка со склада уходит в план ОДНИМ нажатием, без заявки-посредника.
     *
     * План рождается без бригады: менеджер не знает, кто сейчас свободен в
     * цехе. Пока бригады нет, строка стоит в очереди и в «сделано» не идёт.
     */
    public function test_a_shortage_goes_straight_into_the_plan_queue(): void
    {
        Notification::fake();

        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $manager->companies()->attach($this->company);

        $head = User::factory()->create();
        $head->assignRole('production_head');

        $deal = Deal::create([
            'company_id' => $this->company, 'number' => 'QT-900', 'name' => 'X',
            'company_name' => 'Клиент', 'client_name' => 'Брусчатка', 'budget' => 100000,
            'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->value('id'),
            'responsible_user_id' => $manager->id,
        ]);
        $deal->items()->create([
            'product_id' => $this->tile->id, 'name' => $this->tile->name,
            'unit' => 'м²', 'quantity' => 300, 'price' => 9000, 'amount' => 2700000, 'sort' => 0,
        ]);

        $this->actingAs($manager)->post(route('deals.toProduction', $deal->id))
            ->assertSessionHas('success');

        $queued = ProductionPlan::whereNull('brigade_id')->firstOrFail();
        $this->assertSame(300.0, (float) $queued->plan_qty, 'Склад пуст — в план ушёл весь объём.');
        $this->assertSame($deal->id, $queued->deal_id);

        // Весть уходит руководству производства, а не бригадиру: план ещё ничей.
        Notification::assertSentTo($head, ProductionPlanQueued::class);
        Notification::assertNotSentTo($this->foreman, ProductionPlanQueued::class);

        // Вторая сделка на тот же товар СКЛАДЫВАЕТСЯ, а не создаёт вторую строку.
        $deal->items()->create([
            'product_id' => $this->tile->id, 'name' => $this->tile->name,
            'unit' => 'м²', 'quantity' => 200, 'price' => 9000, 'amount' => 1800000, 'sort' => 1,
        ]);
        $this->actingAs($manager)->post(route('deals.toProduction', $deal->fresh()->id));

        $this->assertSame(1, ProductionPlan::whereNull('brigade_id')->count());
        $this->assertSame(800.0, (float) ProductionPlan::whereNull('brigade_id')->value('plan_qty'));
    }

    /** Бригаду очереди назначает начальник производства, не бригадир. */
    public function test_the_production_head_assigns_the_brigade(): void
    {
        $queued = ProductionPlan::create([
            'company_id' => $this->company, 'period_month' => now()->startOfMonth()->toDateString(),
            'brigade_id' => null, 'product_id' => $this->tile->id, 'plan_qty' => 400,
            'unit' => 'м²', 'status' => 'active',
        ]);

        $this->actingAs($this->foreman)
            ->put(route('production.plans.assign', $queued->id), ['brigade_id' => $this->brigade->id])
            ->assertForbidden();

        $this->actingAs($this->master)
            ->put(route('production.plans.assign', $queued->id), ['brigade_id' => $this->brigade->id])
            ->assertSessionHas('success');

        $this->assertSame($this->brigade->id, $queued->fresh()->brigade_id);
    }

    /**
     * У бригады уже есть план на этот товар — объёмы складываются, а очередная
     * строка исчезает: два задания на один товар одной бригаде это одно
     * задание, и уникальный ключ их всё равно не пустит.
     */
    public function test_assigning_merges_into_an_existing_plan(): void
    {
        $existing = $this->plan(['plan_qty' => 1000]);
        $queued = ProductionPlan::create([
            'company_id' => $this->company, 'period_month' => now()->startOfMonth()->toDateString(),
            'brigade_id' => null, 'product_id' => $this->tile->id, 'plan_qty' => 400,
            'unit' => 'м²', 'status' => 'active',
        ]);

        $this->actingAs($this->master)
            ->put(route('production.plans.assign', $queued->id), ['brigade_id' => $this->brigade->id])
            ->assertSessionHas('success');

        $this->assertNull(ProductionPlan::find($queued->id), 'Очередная строка ушла.');
        $this->assertSame(1400.0, (float) $existing->fresh()->plan_qty);
    }
}
