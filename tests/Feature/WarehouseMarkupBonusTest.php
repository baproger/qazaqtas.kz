<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Material;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Services\PayrollService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Бонус за товар со склада: процент от НАЦЕНКИ (продажа − закуп).
 *
 * Правило владельца (16.08.2026): товар покупают за 10 000 ₸, добавляют свой
 * процент и продают; с наценки менеджер получает 2%. По складской части
 * ступенчатый бонус от маржи НЕ начисляется — иначе за один товар платили бы
 * дважды.
 */
class WarehouseMarkupBonusTest extends TestCase
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

    /** Товар на складе: закуп 10 000 ₸ за штуку, наценка 30%. */
    private function stock(float $price = 10000, ?float $markup = 30, float $qty = 10): Material
    {
        $this->actingAs($this->accountant)->post(route('warehouse.receipt'), array_filter([
            'name' => 'Вазон «Тау»',
            'unit' => 'штук',
            'quantity' => $qty,
            'price' => $price,
            'markup_pct' => $markup,
            'payment' => 'cash',
        ], fn ($v) => $v !== null))->assertSessionHasNoErrors();

        return Material::firstOrFail();
    }

    /** Оплаченная сделка — бонус начисляется по факту прихода денег. */
    private function paidDeal(float $budget = 500000): Deal
    {
        $deal = Deal::create([
            'company_id' => Company::where('code', 'QT')->value('id'),
            'number' => 'QT-'.uniqid(), 'name' => 'Сделка', 'company_name' => 'Клиент',
            'budget' => $budget, 'status' => 'active', 'contract_date' => now()->toDateString(),
            'deal_stage_id' => DealStage::where('is_won', true)->value('id'),
            'responsible_user_id' => $this->manager->id,
        ]);

        $invoice = Invoice::create([
            'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id,
            'number' => 'INV-'.uniqid(), 'amount' => $budget, 'status' => 'sent',
        ]);
        Payment::create([
            'invoice_id' => $invoice->id, 'amount' => $budget,
            'payment_date' => now()->toDateString(), 'payment_method' => 'bank',
        ]);

        return $deal;
    }

    private function writeOff(Deal $deal, Material $material, float $qty): Expense
    {
        $this->actingAs($this->manager)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'material_id' => $material->id, 'qty' => $qty, 'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        return Expense::where('material_id', $material->id)->latest('id')->firstOrFail();
    }

    /** Цена продажи фиксируется в момент списания — по ней и считается наценка. */
    public function test_sale_price_is_frozen_at_write_off(): void
    {
        $material = $this->stock();
        $expense = $this->writeOff($this->paidDeal(), $material, 2);

        // 2 × 10 000 = 20 000 закуп; продажа 2 × 13 000 = 26 000.
        $this->assertSame(20000.0, (float) $expense->amount);
        $this->assertSame(26000.0, (float) $expense->sale_amount);

        // Наценку на складе подняли — уже проданный товар не пересчитывается.
        $material->update(['markup_pct' => 90]);
        $this->assertSame(26000.0, (float) $expense->fresh()->sale_amount);
    }

    /** Бонус за складской товар = 2% от наценки. */
    public function test_bonus_is_two_percent_of_the_markup(): void
    {
        $material = $this->stock();
        $deal = $this->paidDeal();
        $this->writeOff($deal, $material, 2);

        // Наценка = 26 000 − 20 000 = 6 000 → бонус за товар = 120 ₸.
        $parts = PayrollService::dealBonus(
            budget: 500000, remainder: 400000, tax: 15000, expense: 20000,
            override: null, userPercent: null, materialSale: 26000, materialCost: 20000,
        );

        $this->assertSame(120.0, $parts['warehouse']);
        $this->assertSame(round($parts['tier'] + 120, 2), $parts['total']);
    }

    /** Ставку бонуса владелец меняет в настройках. */
    public function test_bonus_rate_comes_from_settings(): void
    {
        Setting::set('warehouse_bonus_percent', 5);

        $parts = PayrollService::dealBonus(
            budget: 500000, remainder: 400000, tax: 15000, expense: 20000,
            override: null, userPercent: null, materialSale: 26000, materialCost: 20000,
        );

        $this->assertSame(300.0, $parts['warehouse'], '5% от наценки 6 000 ₸.');
    }

    /**
     * По складской части ступенчатый бонус не идёт: он считается по остальной
     * сделке, поэтому итог меньше, чем был бы по старому правилу.
     */
    public function test_tier_bonus_is_computed_without_the_warehouse_part(): void
    {
        $withStock = PayrollService::dealBonus(
            budget: 500000, remainder: 400000, tax: 15000, expense: 20000,
            override: null, userPercent: null, materialSale: 26000, materialCost: 20000,
        );
        $plain = PayrollService::marginBonus(500000, 400000, 15000);

        $this->assertLessThan($plain, $withStock['tier'], 'Складская выручка вышла из базы ступени.');
        // Ступень считается по сделке без товара: 474 000 договора.
        $this->assertGreaterThan(0, $withStock['tier']);
    }

    /** Сделка без складского товара считается ровно как раньше. */
    public function test_deal_without_stock_is_unchanged(): void
    {
        $parts = PayrollService::dealBonus(
            budget: 500000, remainder: 400000, tax: 15000, expense: 20000,
        );

        $this->assertSame(PayrollService::marginBonus(500000, 400000, 15000), $parts['total']);
        $this->assertSame(0.0, $parts['warehouse']);
    }

    /** Без наценки правило не включается: старые списания бонус не теряют. */
    public function test_stock_without_markup_stays_in_the_tier_bonus(): void
    {
        $material = $this->stock(markup: 0);
        $deal = $this->paidDeal();
        $expense = $this->writeOff($deal, $material, 2);

        $this->assertNull($expense->sale_amount, 'Наценки нет — фиксировать нечего.');

        $materials = PayrollService::materialsByDeal([$deal->id]);
        $this->assertSame(0.0, (float) ($materials['sale'][$deal->id] ?? 0));
    }

    /** Ведомость ЗП показывает бонус целиком, вместе со складской частью. */
    public function test_payroll_row_includes_the_warehouse_bonus(): void
    {
        $material = $this->stock();
        $deal = $this->paidDeal();
        $this->writeOff($deal, $material, 2);

        $row = app(PayrollService::class)->perUser()->firstWhere('uid', $this->manager->id);
        $breakdown = app(PayrollService::class)->dealBreakdown()->get($this->manager->id)->first();

        $this->assertGreaterThan(0, $breakdown['bonus_warehouse']);
        $this->assertSame(120.0, $breakdown['bonus_warehouse'], '2% от наценки 6 000 ₸ при полной оплате.');
        $this->assertSame(round((float) $breakdown['bonus'], 2), round((float) $row['bonus'], 2));
    }

    /** Товар не может «продаться» дороже договора — наценка обрезается суммой сделки. */
    public function test_sale_is_capped_by_the_deal_budget(): void
    {
        $parts = PayrollService::dealBonus(
            budget: 20000, remainder: 15000, tax: 600, expense: 10000,
            override: null, userPercent: null, materialSale: 26000, materialCost: 10000,
        );

        // Продажа обрезана до 20 000: наценка 10 000, бонус 200 ₸.
        $this->assertSame(200.0, $parts['warehouse']);
        $this->assertSame(0.0, $parts['tier'], 'Сделка ушла в товар целиком — ступени нет.');
    }
}
