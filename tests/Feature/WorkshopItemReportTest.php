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
use App\Services\ProductionProgressService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Цепочка «сделал → наряд → мастер → бонус» прямо из карточки цеха.
 *
 * Бригадир отливает и тут же записывает объём у позиции. Запись — обычный
 * сменный наряд: он ждёт мастера, попадает в «Наряды по сменам» и после
 * подтверждения идёт и в бонус, и в «сделано» по сделке. Второго счётчика
 * нет: разойдись он с нарядами, цех и бухгалтерия считали бы разное.
 *
 * Отметка «товар закончен» — отдельно от счётчика: бывает 22 из 24 и «больше
 * не будет». Пока не закрыты все позиции, заказ не уходит на «Логистику».
 */
class WorkshopItemReportTest extends TestCase
{
    use RefreshDatabase;

    private User $foreman;

    private User $director;

    private Brigade $brigade;

    private Deal $deal;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $company = Company::where('code', 'QT')->value('id');

        $this->director = User::factory()->create();
        $this->director->assignRole('director');
        $this->director->companies()->attach($company);

        $this->foreman = User::factory()->create(['name' => 'Асхат Бекболат']);
        $this->foreman->assignRole('foreman');
        $this->foreman->companies()->attach($company);

        $this->brigade = Brigade::create([
            'company_id' => $company, 'name' => 'Бригада №1',
            'workshop' => 'Шымкент', 'foreman_id' => $this->foreman->id, 'is_active' => true,
        ]);
        $this->brigade->members()->attach(User::factory()->create(['name' => 'Ержан'])->id);

        $vase = Product::create(['name' => 'Вазон «Чаша» D800', 'unit' => 'штук', 'price' => 60000, 'is_active' => true]);
        $urn = Product::create(['name' => 'Урна бетонная «Куб»', 'unit' => 'штук', 'price' => 40000, 'is_active' => true]);

        $this->deal = Deal::create([
            'company_id' => $company, 'number' => 'QT-700', 'name' => 'Сквер',
            'company_name' => 'ТОО «Клиент»', 'status' => 'active',
            'foreman_id' => $this->foreman->id,
            'deal_stage_id' => DealStage::query()->orderBy('order')->value('id'),
        ]);

        app(DealItemService::class)->syncDeal($this->deal, [
            ['product_id' => $vase->id, 'quantity' => 24],
            ['product_id' => $urn->id, 'quantity' => 30],
        ]);

        $this->project = Project::create([
            'number' => 'PRJ-700', 'name' => 'Сквер', 'deal_id' => $this->deal->id,
            'workshop' => 'Шымкент', 'status' => 'active',
            'project_stage_id' => ProjectStage::where('workshop', 'Шымкент')->orderBy('order')->value('id'),
        ]);
    }

    private function item(string $name)
    {
        return $this->deal->items()->where('name', 'like', "%{$name}%")->firstOrFail();
    }

    private function report(int $itemId, float $qty)
    {
        return $this->actingAs($this->foreman)
            ->post(route('projects.items.output', [$this->project->id, $itemId]), ['qty' => $qty]);
    }

    /**
     * «Сделал 20 шт» из цеха создаёт сменный наряд, ждущий мастера.
     *
     * Пока мастер не подтвердил, это «ждёт», а не «сделано»: иначе выработку
     * можно было бы приписать себе и получить за неё бонус.
     */
    public function test_reporting_creates_a_pending_work_order(): void
    {
        $vase = $this->item('Вазон');

        $this->report($vase->id, 20)->assertSessionHasNoErrors();

        $order = WorkOrder::firstWhere('deal_item_id', $vase->id);
        $this->assertNotNull($order);
        $this->assertSame('draft', $order->status);
        $this->assertSame($this->brigade->id, $order->brigade_id);
        $this->assertSame($this->project->id, $order->project_id);
        $this->assertSame('Вазон «Чаша» D800', $order->product);

        $stats = app(ProductionProgressService::class)->forItems([$vase]);
        $this->assertSame(0.0, $stats[$vase->id]['done'], 'Без мастера это ещё не сделано');
        $this->assertSame(20.0, $stats[$vase->id]['pending']);
    }

    /** Единица позиции решает, во что писать объём: штуки — в штуки. */
    public function test_the_unit_decides_the_measure(): void
    {
        $vase = $this->item('Вазон');
        $this->report($vase->id, 20);

        $order = WorkOrder::firstWhere('deal_item_id', $vase->id);
        $workers = $order->lines()->where('role', 'worker')->get();

        $this->assertSame(20.0, (float) $workers->sum('qty_pcs'));
        $this->assertSame(0.0, (float) $workers->sum('qty_m2'));
    }

    /**
     * Вся цепочка: запись → мастер → «сделано» и бонус.
     *
     * Одна и та же цифра доходит до карточки цеха и до страницы производства.
     */
    public function test_the_whole_chain_ends_in_done_and_bonus(): void
    {
        $vase = $this->item('Вазон');
        $this->report($vase->id, 20);

        $order = WorkOrder::firstWhere('deal_item_id', $vase->id);
        $this->actingAs($this->director)
            ->patch(route('production.orders.confirm', $order->id))
            ->assertSessionHasNoErrors();

        $is = fn (float $expect) => fn ($value) => (float) $value === $expect;

        $this->actingAs($this->foreman)->get(route('projects.show', $this->project->id))
            ->assertInertia(fn ($page) => $page
                ->where("itemProgress.{$vase->id}.done", $is(20.0))
                ->where("itemProgress.{$vase->id}.left", $is(4.0))
                ->etc());

        // Наряд виден в «Нарядах по сменам», а бонус — в «Кто сколько сделал».
        $this->actingAs($this->director)->get(route('production.index'))
            ->assertInertia(fn ($page) => $page
                ->where('orders', fn ($orders) => collect($orders)->contains(fn ($o) => $o['status'] === 'confirmed'))
                ->where('byPerson', fn ($people) => collect($people)->sum('pcs') > 0)
                ->where('planSummary.pcs.done', $is(20.0))
                ->etc());
    }

    /** Дописал остаток — позиция закрылась по объёму. */
    public function test_a_second_report_adds_up(): void
    {
        $vase = $this->item('Вазон');
        $this->report($vase->id, 20);
        $this->report($vase->id, 4);

        foreach (WorkOrder::where('deal_item_id', $vase->id)->pluck('id') as $id) {
            $this->actingAs($this->director)->patch(route('production.orders.confirm', $id));
        }

        $stats = app(ProductionProgressService::class)->forItems([$vase]);
        $this->assertSame(24.0, $stats[$vase->id]['done']);
        $this->assertSame(0.0, $stats[$vase->id]['left']);
    }

    /** Объём смены делится по рабочим и сходится с введённым до копейки. */
    public function test_the_volume_is_split_between_workers_without_loss(): void
    {
        $this->brigade->members()->attach(User::factory()->create()->id);
        $this->brigade->members()->attach(User::factory()->create()->id);

        $vase = $this->item('Вазон');
        $this->report($vase->id, 20);   // 3 рабочих, 20 не делится нацело

        $order = WorkOrder::firstWhere('deal_item_id', $vase->id);
        $this->assertSame(20.0, (float) $order->lines()->where('role', 'worker')->sum('qty_pcs'));
    }

    /** Товар отмечается законченным и возвращается в работу. */
    public function test_an_item_can_be_finished_and_reopened(): void
    {
        $vase = $this->item('Вазон');

        $this->actingAs($this->foreman)
            ->post(route('projects.items.finish', [$this->project->id, $vase->id]))
            ->assertSessionHasNoErrors();

        $vase->refresh();
        $this->assertNotNull($vase->finished_at);
        $this->assertSame($this->foreman->id, $vase->finished_by);

        $this->actingAs($this->foreman)->post(route('projects.items.finish', [$this->project->id, $vase->id]));
        $this->assertNull($vase->fresh()->finished_at);
    }

    /**
     * Заказ не уходит на «Логистику», пока не закрыты все товары.
     *
     * Уедь машина раньше — на объект приехала бы половина заказа, а по
     * бумагам он был бы сдан.
     */
    public function test_the_order_stays_until_every_item_is_finished(): void
    {
        $vase = $this->item('Вазон');
        $urn = $this->item('Урна');

        $this->actingAs($this->foreman)->post(route('projects.items.finish', [$this->project->id, $vase->id]));

        $this->actingAs($this->foreman)->post(route('projects.toAct', $this->project->id))
            ->assertSessionHas('error');
        $this->assertSame('active', $this->project->fresh()->status);

        $this->actingAs($this->foreman)->post(route('projects.items.finish', [$this->project->id, $urn->id]));

        $this->actingAs($this->foreman)->post(route('projects.toAct', $this->project->id))
            ->assertSessionHas('success');
        $this->assertSame('completed', $this->project->fresh()->status);
    }

    /**
     * Отправка сделки В цех незакрытыми позициями не блокируется.
     *
     * Проверка «все товары закончены» относится к ВЫХОДУ из цеха. На входе
     * их не делал ещё никто, и требовать отметку значило бы не пустить в цех
     * ни один заказ.
     */
    public function test_sending_a_deal_to_the_workshop_is_not_blocked(): void
    {
        $fresh = Deal::create([
            'company_id' => $this->deal->company_id, 'number' => 'QT-702', 'name' => 'Новый',
            'company_name' => 'ТОО «Новый»', 'status' => 'active',
            'responsible_user_id' => $this->director->id,
            'deal_stage_id' => DealStage::query()->orderBy('order')->value('id'),
        ]);
        $fresh->items()->create(['name' => 'Вазон', 'unit' => 'штук', 'quantity' => 5, 'sort' => 0]);

        $this->actingAs($this->director)
            ->post(route('deals.toWorkshop', $fresh->id), ['workshop' => 'Шымкент'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($fresh->fresh()->project, 'Заказ цеха создан');
    }

    /** Позицию чужой сделки на этот заказ не списать. */
    public function test_an_item_of_another_deal_is_rejected(): void
    {
        $other = Deal::create([
            'company_id' => $this->deal->company_id, 'number' => 'QT-701', 'name' => 'Чужой',
            'company_name' => 'ТОО «Другой»', 'status' => 'active',
            'deal_stage_id' => DealStage::query()->orderBy('order')->value('id'),
        ]);
        $alien = $other->items()->create(['name' => 'Бордюр', 'unit' => 'штук', 'quantity' => 50, 'sort' => 0]);

        $this->report($alien->id, 10)->assertNotFound();
        $this->assertSame(0, WorkOrder::count());
    }

    /** Цех другого города закрыт и для записи выработки. */
    public function test_another_city_cannot_report(): void
    {
        $alien = User::factory()->create(['workshops' => ['Алматы']]);
        $alien->assignRole('foreman');
        $alien->companies()->attach($this->deal->company_id);

        $this->actingAs($alien)
            ->post(route('projects.items.output', [$this->project->id, $this->item('Вазон')->id]), ['qty' => 5])
            ->assertForbidden();
    }
}
