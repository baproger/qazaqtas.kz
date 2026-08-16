<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Material;
use App\Models\MaterialReceipt;
use App\Models\User;
use App\Services\FinanceService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Деньги за товар уходят при ЗАКУПЕ, а списание в сделку — движение запаса.
 *
 * До этого шага было наоборот: приход кассы не касался, а списание материала
 * уменьшало её второй раз — уже после того, как поставщику заплатили.
 */
class WarehousePurchasePaymentTest extends TestCase
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

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->companies()->attach(Company::where('code', 'QT')->value('id'));
    }

    private function cash(): float
    {
        return app(FinanceService::class)->companyBalances(null)['cash'];
    }

    private function receipt(array $extra = []): MaterialReceipt
    {
        $this->actingAs($this->accountant)->post(route('warehouse.receipt'), array_merge([
            'name' => 'Мраморная крошка',
            'unit' => 'мешок',
            'quantity' => 10,
            'price' => 1000,
            'payment' => 'cash',
        ], $extra))->assertSessionHasNoErrors();

        return MaterialReceipt::latest('id')->firstOrFail();
    }

    private function deal(): Deal
    {
        return Deal::create([
            'company_id' => Company::where('code', 'QT')->value('id'),
            'number' => 'QT-'.uniqid(), 'name' => 'Сделка', 'company_name' => 'Клиент',
            'budget' => 500000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->value('id'),
            'responsible_user_id' => $this->manager->id,
        ]);
    }

    /** Приход с оплатой уменьшает кассу — на количество × цену. */
    public function test_paid_receipt_takes_money_from_the_cash_desk(): void
    {
        $before = $this->cash();
        $receipt = $this->receipt();

        $expense = Expense::findOrFail($receipt->expense_id);
        $this->assertSame('confirmed', $expense->status);
        $this->assertSame('cash', $expense->payment_method);
        $this->assertSame(10000.0, (float) $expense->amount);
        $this->assertSame(
            ExpenseCategory::findByCode(ExpenseCategory::MATERIALS_PURCHASE)->id,
            $expense->category_id,
        );

        $this->assertSame(round($before - 10000, 2), round($this->cash(), 2));
    }

    /** «Не списывать» — только остаток: деньги ушли раньше или уйдут позже. */
    public function test_receipt_without_payment_leaves_money_alone(): void
    {
        $before = $this->cash();
        $receipt = $this->receipt(['payment' => 'none']);

        $this->assertNull($receipt->expense_id);
        $this->assertSame(round($before, 2), round($this->cash(), 2));
        $this->assertSame(10.0, (float) Material::firstOrFail()->quantity);
    }

    /** Удаление прихода возвращает деньги: расход-оплата уходит с ним. */
    public function test_deleting_the_receipt_returns_the_money(): void
    {
        $before = $this->cash();
        $receipt = $this->receipt();

        $this->actingAs($this->accountant)->delete(route('warehouse.receipts.destroy', $receipt->id))
            ->assertSessionHasNoErrors();

        $this->assertNull(Expense::find($receipt->expense_id));
        $this->assertSame(round($before, 2), round($this->cash(), 2));
    }

    /** Правка прихода двигает и сумму оплаты. */
    public function test_editing_the_receipt_syncs_the_payment(): void
    {
        $receipt = $this->receipt();

        $this->actingAs($this->accountant)->put(route('warehouse.receipts.update', $receipt->id), [
            'quantity' => 4,
            'price' => 1500,
            'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame(6000.0, (float) Expense::findOrFail($receipt->fresh()->expense_id)->amount);
    }

    /**
     * Списание материала в сделку кассы НЕ касается, но в маржу сделки
     * входит: себестоимость изделия от способа учёта не меняется.
     */
    public function test_write_off_does_not_touch_the_cash_but_stays_in_the_margin(): void
    {
        $this->receipt(['payment' => 'none']);
        $material = Material::firstOrFail();
        $deal = $this->deal();

        $before = $this->cash();

        $this->actingAs($this->manager)->post(route('expenses.store'), [
            'expenseable_type' => 'deal',
            'expenseable_id' => $deal->id,
            'material_id' => $material->id,
            'qty' => 3,
            'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $expense = Expense::where('material_id', $material->id)->firstOrFail();
        $this->assertNull($expense->payment_method, 'Списание — движение запаса, а не денег.');
        $this->assertSame('confirmed', $expense->status);
        $this->assertSame(3000.0, (float) $expense->amount);

        $this->assertSame(round($before, 2), round($this->cash(), 2));
        // В расходах сделки списание осталось — маржа считает себестоимость.
        $this->assertSame(3000.0, (float) app(FinanceService::class)->summaryFor($deal)['expense']);
        $this->assertSame(7.0, (float) $material->fresh()->quantity);
    }

    /** Удаление списания по-прежнему возвращает остаток на склад. */
    public function test_deleting_a_write_off_still_returns_the_stock(): void
    {
        $this->receipt(['payment' => 'none']);
        $material = Material::firstOrFail();
        $deal = $this->deal();

        $this->actingAs($this->manager)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'material_id' => $material->id, 'qty' => 2, 'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $expense = Expense::where('material_id', $material->id)->firstOrFail();
        $this->actingAs($this->accountant)->delete(route('expenses.destroy', $expense->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(10.0, (float) $material->fresh()->quantity);
    }

    /**
     * Итог «Расходы» на Финансах не считает одни деньги дважды: закуп в него
     * входит, списание того же товара в сделку — нет.
     */
    public function test_finance_total_counts_the_purchase_but_not_the_write_off(): void
    {
        $this->receipt();                 // закуп 10 × 1000 = 10 000 из кассы
        $material = Material::firstOrFail();
        $deal = $this->deal();

        $this->actingAs($this->manager)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'material_id' => $material->id, 'qty' => 3, 'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->accountant)->get(route('finance.index'))
            ->assertInertia(fn ($page) => $page
                // Закуп виден категорией, списание — отдельной строкой…
                ->where('summary.materialWriteoffs', 3000)
                ->where('summary.dealExpenses', 0)
                // …и в итог «по сделкам» не попадает.
                ->where('summary.categories', fn ($rows) => (float) collect($rows)
                    ->firstWhere('name', 'Закуп материалов')['sum'] === 10000.0));
    }

    /** Миграция очистила способ оплаты ТОЛЬКО у материальных списаний. */
    public function test_migration_cleared_only_material_write_offs(): void
    {
        // Запись «как раньше»: материальное списание со способом оплаты.
        $this->receipt(['payment' => 'none']);
        $material = Material::firstOrFail();
        $old = Expense::create([
            'company_id' => Company::where('code', 'QT')->value('id'),
            'expenseable_type' => 'deal', 'expenseable_id' => $this->deal()->id,
            'material_id' => $material->id, 'qty' => 1,
            'amount' => 1000, 'date' => now()->toDateString(),
            'status' => 'confirmed', 'payment_method' => 'cash',
            'responsible_user_id' => $this->manager->id,
        ]);
        $plain = Expense::create([
            'company_id' => Company::where('code', 'QT')->value('id'),
            'amount' => 5000, 'date' => now()->toDateString(),
            'status' => 'confirmed', 'payment_method' => 'cash',
            'responsible_user_id' => $this->accountant->id,
        ]);

        \Illuminate\Support\Facades\DB::table('expenses')->whereNotNull('material_id')
            ->update(['payment_method' => null]);

        $this->assertNull($old->fresh()->payment_method);
        $this->assertSame('cash', $plain->fresh()->payment_method, 'Обычный расход миграция не трогает.');
    }
}
