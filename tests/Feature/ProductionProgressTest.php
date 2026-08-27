<?php

namespace Tests\Feature;

use App\Models\Brigade;
use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\DealItemService;
use App\Services\ProductionBonusService;
use App\Services\ProductionProgressService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * План и факт производства: сколько по сделке заказано и сколько сделано.
 *
 * План — позиция сделки, факт — подтверждённые наряды по ней. Число считается
 * в одном месте и показывается в сделке, в цехе и на производстве: разойдись
 * счёт, менеджер и бригадир видели бы разный остаток по одному заказу.
 */
class ProductionProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private User $foreman;

    private Brigade $brigade;

    private Deal $deal;

    private int $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->company = Company::where('code', 'QT')->value('id');

        $this->director = User::factory()->create();
        $this->director->assignRole('director');
        $this->director->companies()->attach($this->company);

        $this->foreman = User::factory()->create(['name' => 'Асхат Бекболат']);
        $this->foreman->assignRole('foreman');
        $this->foreman->companies()->attach($this->company);

        $worker = User::factory()->create(['name' => 'Ержан']);
        $worker->assignRole('employee');

        $this->brigade = Brigade::create([
            'company_id' => $this->company, 'name' => 'Бригада №1',
            'workshop' => 'Шымкент', 'foreman_id' => $this->foreman->id, 'is_active' => true,
        ]);
        $this->brigade->members()->attach($worker->id);

        $tile = Product::create(['name' => 'Плитка «Ромб»', 'unit' => 'м²', 'price' => 9000, 'is_active' => true]);
        $urn = Product::create(['name' => 'Урна «Конус»', 'unit' => 'штук', 'price' => 40000, 'is_active' => true]);

        $this->deal = Deal::create([
            'company_id' => $this->company, 'number' => 'QT-500', 'name' => 'Двор ЖК',
            'company_name' => 'ТОО «Клиент»', 'status' => 'active',
            'foreman_id' => $this->foreman->id,
            'deal_stage_id' => DealStage::query()->orderBy('order')->value('id'),
        ]);

        app(DealItemService::class)->syncDeal($this->deal, [
            ['product_id' => $tile->id, 'quantity' => 200],
            ['product_id' => $urn->id, 'quantity' => 10],
        ]);

        Project::create([
            'number' => 'PRJ-500', 'name' => 'Двор ЖК', 'deal_id' => $this->deal->id,
            'workshop' => 'Шымкент', 'status' => 'active',
            'project_stage_id' => ProjectStage::where('workshop', 'Шымкент')->orderBy('order')->value('id'),
        ]);
    }

    private function item(string $name)
    {
        return $this->deal->items()->where('name', 'like', "%{$name}%")->firstOrFail();
    }

    /** Наряд бригады по позиции: объём рабочих и строка бригадира на смену. */
    private function order(int $itemId, float $qtyM2, float $qtyPcs = 0, string $status = 'confirmed'): WorkOrder
    {
        $order = WorkOrder::create([
            'company_id' => $this->company, 'brigade_id' => $this->brigade->id,
            'deal_item_id' => $itemId, 'date' => now()->toDateString(),
            'status' => $status, 'created_by' => $this->foreman->id,
        ]);

        app(ProductionBonusService::class)->syncLines($order->load('brigade'), [
            ['user_id' => $this->brigade->members()->value('users.id'), 'qty_m2' => $qtyM2, 'qty_pcs' => $qtyPcs],
        ]);

        return $order;
    }

    /**
     * Факт — только объём рабочих.
     *
     * У наряда есть ещё строка бригадира на весь объём смены: она нужна для
     * его бонуса. Сложи все строки — и смена посчиталась бы дважды, а план по
     * сделке закрылся бы вдвое быстрее, чем сделан.
     */
    public function test_the_foreman_line_is_not_counted_twice(): void
    {
        $tile = $this->item('Ромб');
        $order = $this->order($tile->id, 60);

        // В наряде две строки: рабочий 60 м² и бригадир 60 м².
        $this->assertSame(120.0, (float) $order->lines()->sum('qty_m2'));

        $stats = app(ProductionProgressService::class)->forItems([$tile]);
        $this->assertSame(60.0, $stats[$tile->id]['done'], 'Сделано ровно столько, сколько отлили');
        $this->assertSame(140.0, $stats[$tile->id]['left']);
        $this->assertSame(30.0, $stats[$tile->id]['percent']);
    }

    /** Неподтверждённый наряд в «сделано» не идёт, но виден отдельно. */
    public function test_an_unconfirmed_order_waits_apart(): void
    {
        $tile = $this->item('Ромб');
        $this->order($tile->id, 50);
        $this->order($tile->id, 30, status: 'draft');

        $stats = app(ProductionProgressService::class)->forItems([$tile]);

        $this->assertSame(50.0, $stats[$tile->id]['done']);
        $this->assertSame(30.0, $stats[$tile->id]['pending'], 'Внесено, но мастер не подтвердил');
        $this->assertSame(150.0, $stats[$tile->id]['left'], 'Остаток считается по подтверждённому');
    }

    /** Метры и штуки не смешиваются: единица позиции решает, что считать. */
    public function test_pieces_and_squares_are_counted_apart(): void
    {
        $tile = $this->item('Ромб');
        $urn = $this->item('Урна');

        $this->order($tile->id, 200);
        $this->order($urn->id, 0, 4);

        $stats = app(ProductionProgressService::class)->forItems([$tile, $urn]);

        $this->assertSame('m2', $stats[$tile->id]['measure']);
        $this->assertSame(200.0, $stats[$tile->id]['done']);
        $this->assertSame(0.0, $stats[$tile->id]['left']);

        $this->assertSame('pcs', $stats[$urn->id]['measure']);
        $this->assertSame(4.0, $stats[$urn->id]['done']);
        $this->assertSame(6.0, $stats[$urn->id]['left']);
    }

    /** Сделали больше заказанного — это видно, а остаток не уходит в минус. */
    public function test_overproduction_is_flagged_not_negative(): void
    {
        $tile = $this->item('Ромб');
        $this->order($tile->id, 230);

        $stats = app(ProductionProgressService::class)->forItems([$tile]);

        $this->assertTrue($stats[$tile->id]['over']);
        $this->assertSame(0.0, $stats[$tile->id]['left']);
    }

    /** По каждой бригаде видно, сколько сделала именно она. */
    public function test_output_is_split_by_brigade(): void
    {
        $tile = $this->item('Ромб');
        $second = Brigade::create([
            'company_id' => $this->company, 'name' => 'Бригада №2',
            'workshop' => 'Шымкент', 'foreman_id' => $this->foreman->id, 'is_active' => true,
        ]);
        $second->members()->attach(User::factory()->create()->id);

        $this->order($tile->id, 60);
        $other = WorkOrder::create([
            'company_id' => $this->company, 'brigade_id' => $second->id,
            'deal_item_id' => $tile->id, 'date' => now()->toDateString(),
            'status' => 'confirmed', 'created_by' => $this->foreman->id,
        ]);
        app(ProductionBonusService::class)->syncLines($other->load('brigade'), [
            ['user_id' => $second->members()->value('users.id'), 'qty_m2' => 40],
        ]);

        $byBrigade = app(ProductionProgressService::class)->byBrigade([$tile->id]);
        $volumes = collect($byBrigade[$tile->id])->pluck('m2', 'brigade')->all();

        $this->assertSame(['Бригада №1' => 60.0, 'Бригада №2' => 40.0], $volumes);
    }

    /** Одна и та же цифра приходит в сделку, в цех и на производство. */
    public function test_the_same_number_everywhere(): void
    {
        $tile = $this->item('Ромб');
        $this->order($tile->id, 80);

        $expected = ['done' => 80.0, 'plan' => 200.0, 'left' => 120.0];

        // JSON отдаёт 80.0 как 80 — сравниваем числа, а не их запись.
        $is = fn (float $expect) => fn ($value) => (float) $value === $expect;

        $this->actingAs($this->director)->get(route('deals.show', $this->deal->id))
            ->assertInertia(fn ($page) => $page
                ->where("itemProgress.{$tile->id}.done", $is($expected['done']))
                ->where("itemProgress.{$tile->id}.left", $is($expected['left']))
                ->etc());

        $project = $this->deal->project;
        $this->actingAs($this->foreman)->get(route('projects.show', $project->id))
            ->assertInertia(fn ($page) => $page
                ->where("itemProgress.{$tile->id}.done", $is($expected['done']))
                ->where("itemProgress.{$tile->id}.left", $is($expected['left']))
                ->etc());

        $this->actingAs($this->director)->get(route('production.index'))
            ->assertInertia(fn ($page) => $page
                ->where('planSummary.m2.plan', $is($expected['plan']))
                ->where('planSummary.m2.done', $is($expected['done']))
                ->where('planSummary.m2.left', $is($expected['left']))
                ->etc());
    }

    /** Наряд, заведённый по позиции, попадает в план сразу — без ручного ввода. */
    public function test_creating_an_order_with_an_item_fills_the_plan(): void
    {
        $tile = $this->item('Ромб');

        $this->actingAs($this->foreman)->post(route('production.orders.store'), [
            'brigade_id' => $this->brigade->id,
            'date' => now()->toDateString(),
            'deal_item_id' => $tile->id,
            'lines' => [['user_id' => $this->brigade->members()->value('users.id'), 'qty_m2' => 45]],
        ])->assertSessionHasNoErrors();

        $order = WorkOrder::latest('id')->first();
        $this->assertSame($tile->id, $order->deal_item_id);
        $this->assertSame('Плитка «Ромб»', $order->product, 'Изделие подставилось из позиции');
        $this->assertSame($this->deal->project->id, $order->project_id, 'Заказ цеха подставился из сделки');

        // Пока мастер не подтвердил — это «ждёт», а не «сделано».
        $stats = app(ProductionProgressService::class)->forItems([$tile]);
        $this->assertSame(0.0, $stats[$tile->id]['done']);
        $this->assertSame(45.0, $stats[$tile->id]['pending']);
    }

    /** Бригадир видит в плане только то, над чем работали его бригады. */
    public function test_the_foreman_sees_only_his_brigades_work(): void
    {
        $tile = $this->item('Ромб');
        $this->order($tile->id, 60);

        $strangerBrigade = Brigade::create([
            'company_id' => $this->company, 'name' => 'Чужая',
            'workshop' => 'Шымкент', 'foreman_id' => User::factory()->create()->id, 'is_active' => true,
        ]);
        $otherDeal = Deal::create([
            'company_id' => $this->company, 'number' => 'QT-501', 'name' => 'Чужой объект',
            'company_name' => 'ТОО «Другой»', 'status' => 'active',
            'deal_stage_id' => DealStage::query()->orderBy('order')->value('id'),
        ]);
        $otherItem = $otherDeal->items()->create(['name' => 'Бордюр', 'unit' => 'м²', 'quantity' => 100, 'sort' => 0]);
        WorkOrder::create([
            'company_id' => $this->company, 'brigade_id' => $strangerBrigade->id,
            'deal_item_id' => $otherItem->id, 'date' => now()->toDateString(),
            'status' => 'confirmed', 'created_by' => $this->director->id,
        ]);

        $this->actingAs($this->foreman)->get(route('production.index'))
            ->assertInertia(fn ($page) => $page
                ->where('plan', fn ($plan) => collect($plan)->pluck('name')->doesntContain('Бордюр'))
                ->etc());
    }
}
