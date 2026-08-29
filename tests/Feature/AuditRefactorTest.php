<?php

namespace Tests\Feature;

use App\Models\Brigade;
use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductionPlan;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ProductionProgressService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Регрессии на находки полного аудита (29.08.2026). */
class AuditRefactorTest extends TestCase
{
    use RefreshDatabase;

    private Company $qt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $this->qt = Company::where('code', 'QT')->firstOrFail();
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        $u->companies()->attach($this->qt->id);

        return $u;
    }

    private function deal(User $owner): Deal
    {
        return Deal::create([
            'company_id' => $this->qt->id,
            'number' => 'D-'.uniqid(), 'name' => 'Сделка', 'company_name' => 'ТОО', 'customer_bin' => '123456789012',
            'budget' => 100000, 'partner_pct' => 10,
            'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->first()->id,
            'responsible_user_id' => $owner->id,
        ]);
    }

    /** Роль без права на суммы не может менять долю партнёра — она уменьшает остаток, из которого считается бонус. */
    public function test_role_without_money_cannot_change_partner_pct(): void
    {
        Role::where('name', 'manager')->update(['sees_money' => false]);
        $mgr = $this->user('manager');
        $deal = $this->deal($mgr);

        $this->actingAs($mgr)->withSession(['company_id' => $this->qt->id])->put(route('deals.update', $deal), [
            'company_name' => 'ТОО', 'client_name' => 'И', 'address' => 'Алматы', 'budget' => 100000,
            'deal_stage_id' => $deal->deal_stage_id, 'responsible_user_id' => $mgr->id,
            'partner_pct' => 0,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(10.0, (float) $deal->fresh()->partner_pct);
    }

    /** Счёт нельзя создать сразу «оплаченным»: статус оплаты выводится из платежей. */
    public function test_invoice_cannot_be_created_as_paid(): void
    {
        $mgr = $this->user('manager');
        $deal = $this->deal($mgr);

        $this->actingAs($mgr)->withSession(['company_id' => $this->qt->id])->post(route('invoices.store'), [
            'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id, 'amount' => 50000, 'status' => 'paid',
        ])->assertSessionHasNoErrors();

        $this->assertSame('draft', Invoice::firstOrFail()->status);
    }

    /** Подсказка по БИН не выдаёт бюджеты тем, кто не видит деньги. */
    public function test_bin_lookup_hides_budget_from_non_money_roles(): void
    {
        Role::where('name', 'manager')->update(['sees_money' => false]);
        $mgr = $this->user('manager');
        $this->deal($mgr);

        $resp = $this->actingAs($mgr)->withSession(['company_id' => $this->qt->id])
            ->getJson(route('deals.binLookup', ['bin' => '123456789012']))->assertOk();

        $this->assertCount(1, $resp->json('history'));
        $this->assertNull($resp->json('history.0.budget'));
    }

    /** Пустой список позиций обнуляет сумму: бюджет из браузера не принимается. */
    public function test_clearing_items_resets_budget_instead_of_trusting_the_client(): void
    {
        $mgr = $this->user('manager');
        $deal = $this->deal($mgr);
        $product = Product::create(['name' => 'Плитка', 'unit' => 'м²', 'price' => 9000, 'is_active' => true, 'is_service' => false]);
        $deal->items()->create(['product_id' => $product->id, 'name' => 'Плитка', 'unit' => 'м²', 'quantity' => 10, 'price' => 9000, 'amount' => 90000, 'sort' => 0]);

        $this->actingAs($mgr)->withSession(['company_id' => $this->qt->id])->put(route('deals.update', $deal), [
            'company_name' => 'ТОО', 'client_name' => 'И', 'address' => 'Алматы', 'budget' => 999999,
            'deal_stage_id' => $deal->deal_stage_id, 'responsible_user_id' => $mgr->id, 'items' => [],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $deal->items()->count());
        $this->assertSame(0.0, (float) $deal->fresh()->budget);
    }

    /** Две сделки на один товар складываются в одну строку, но ни одна не удваивает свой вклад. */
    public function test_shortage_button_is_idempotent_across_deals_sharing_a_product(): void
    {
        $mgr = $this->user('manager');
        $product = Product::create(['name' => 'Плитка', 'unit' => 'м²', 'price' => 9000, 'is_active' => true, 'is_service' => false]);
        $a = $this->deal($mgr);
        $a->items()->create(['product_id' => $product->id, 'name' => 'Плитка', 'unit' => 'м²', 'quantity' => 300, 'price' => 9000, 'amount' => 2700000, 'sort' => 0]);
        $b = $this->deal($mgr);
        $b->items()->create(['product_id' => $product->id, 'name' => 'Плитка', 'unit' => 'м²', 'quantity' => 200, 'price' => 9000, 'amount' => 1800000, 'sort' => 0]);

        $as = fn () => $this->actingAs($mgr)->withSession(['company_id' => $this->qt->id]);
        $as()->post(route('deals.toProduction', $a));
        $as()->post(route('deals.toProduction', $b));
        $as()->post(route('deals.toProduction', $b));
        $as()->post(route('deals.toProduction', $b));

        $this->assertSame(1, ProductionPlan::whereNull('brigade_id')->count());
        $this->assertSame(500.0, (float) ProductionPlan::whereNull('brigade_id')->value('plan_qty'));
        $as()->get(route('deals.show', $b))->assertInertia(fn ($p) => $p->where('stock.can_send', false)->etc());
    }

    /** Отклонённый наряд — ни «сделано», ни «ожидает»: повторная подача не удваивает ожидание. */
    public function test_rejected_work_order_counts_as_neither_done_nor_pending(): void
    {
        $foreman = $this->user('foreman');
        $master = $this->user('production_head');
        $brigade = Brigade::create(['company_id' => $this->qt->id, 'name' => 'Б1', 'workshop' => 'Шымкент', 'foreman_id' => $foreman->id, 'is_active' => true]);
        $product = Product::create(['name' => 'Плитка', 'unit' => 'м²', 'price' => 9000, 'is_active' => true, 'is_service' => false]);
        $plan = ProductionPlan::create(['company_id' => $this->qt->id, 'period_month' => now()->startOfMonth()->toDateString(),
            'brigade_id' => $brigade->id, 'product_id' => $product->id, 'plan_qty' => 1000, 'unit' => 'м²', 'status' => 'active']);

        $this->actingAs($foreman)->withSession(['company_id' => $this->qt->id])->post(route('production.plans.output', $plan->id), ['qty' => 100]);
        $order = WorkOrder::firstWhere('production_plan_id', $plan->id);
        $this->actingAs($master)->withSession(['company_id' => $this->qt->id])->patch(route('production.orders.reject', $order->id), ['reason' => 'нет фото']);
        $this->actingAs($foreman)->withSession(['company_id' => $this->qt->id])->post(route('production.plans.output', $plan->id), ['qty' => 100]);

        $progress = app(ProductionProgressService::class)->forPlans(collect([$plan->fresh()]))[$plan->id];
        $this->assertSame(0.0, (float) $progress['done']);
        $this->assertSame(100.0, (float) $progress['pending']);
    }

    /** Удалённая владельцем роль не роняет подтверждение расхода пятисоткой. */
    public function test_expense_confirmation_survives_a_deleted_financist_role(): void
    {
        $admin = $this->user('admin');
        $mgr = $this->user('manager');
        $deal = $this->deal($mgr);
        $expense = Expense::create(['expenseable_type' => 'deal', 'expenseable_id' => $deal->id, 'amount' => 5000,
            'date' => now()->toDateString(), 'status' => 'pending', 'responsible_user_id' => $mgr->id, 'description' => 'Доставка']);

        Role::where('name', 'financist')->delete();
        Storage::fake('local');

        $this->actingAs($admin)->withSession(['company_id' => $this->qt->id])
            ->patch(route('expenses.confirm', $expense), ['payment_method' => 'cash', 'file' => UploadedFile::fake()->image('чек.jpg')])
            ->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $expense->fresh()->status);
    }

    /** Заказы с сайта изолированы по фирме: чужой заказ не виден и не редактируется. */
    public function test_site_orders_are_scoped_to_current_company(): void
    {
        $alt = Company::firstOrCreate(['code' => 'ALT'], ['name' => 'ALT', 'is_active' => true]);
        $mine = Order::create(['number' => 'ZT-1', 'company_id' => $this->qt->id, 'name' => 'Мой', 'phone' => '1', 'total' => 100, 'status' => 'new']);
        $foreign = Order::create(['number' => 'ZT-2', 'company_id' => $alt->id, 'name' => 'Чужой', 'phone' => '2', 'total' => 900, 'status' => 'new']);

        $mgr = $this->user('manager');
        $this->actingAs($mgr)->withSession(['company_id' => $this->qt->id])->get(route('siteOrders.index'))
            ->assertInertia(fn ($p) => $p->component('SiteOrders/Index')
                ->where('stats.new', 1)
                ->where('orders.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$mine->id]));

        $this->actingAs($mgr)->withSession(['company_id' => $this->qt->id])
            ->patch(route('siteOrders.update', $foreign), ['status' => 'done'])->assertForbidden();
        $this->actingAs($mgr)->withSession(['company_id' => $this->qt->id])
            ->post(route('siteOrders.convert', $foreign))->assertForbidden();
        $this->assertSame('new', $foreign->fresh()->status);

        $this->actingAs($mgr)->withSession(['company_id' => $this->qt->id])
            ->patch(route('siteOrders.update', $mine), ['status' => 'done'])->assertRedirect();
        $this->assertSame('done', $mine->fresh()->status);
    }

    /** Право снято в Настройках → Права — страница закрыта, даже если роль «подходит». */
    public function test_pages_follow_access_settings_not_only_role_lists(): void
    {
        $foreman = $this->user('foreman');
        Role::findByName('foreman')->revokePermissionTo(['deal.viewAny', 'project.viewAny']);
        $this->actingAs($foreman)->withSession(['company_id' => $this->qt->id])->get(route('deals.index'))->assertForbidden();
        $this->actingAs($foreman)->withSession(['company_id' => $this->qt->id])->get(route('production.plans.index'))->assertForbidden();

        $fin = $this->user('financist');
        Role::findByName('financist')->revokePermissionTo(['payment.viewAny', 'expense.viewAny', 'product.viewAny']);
        $as = fn () => $this->actingAs($fin)->withSession(['company_id' => $this->qt->id]);
        $as()->get(route('cashBook.index'))->assertForbidden();
        $as()->get(route('expensesBoard.index'))->assertForbidden();
        $as()->get(route('warehouse.index'))->assertForbidden();
        // Права на месте — страница открыта.
        $as()->get(route('finance.debts'))->assertOk();
    }
}
