<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Material;
use App\Models\ProductCategory;
use App\Models\Setting;
use App\Models\User;
use App\Services\PayrollService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Перепродажа оплачивается ставкой по ТИПУ СДЕЛКИ.
 *
 * Раньше складской товар давал отдельный бонус «процент от наценки», а
 * остальная часть сделки считалась ступенями от маржи. С 21.08.2026 правило
 * владельца проще: своё производство — одна ставка от остатка, перепродажа —
 * другая. Отдельного бонуса за наценку больше нет — иначе за перепродажу
 * платили бы дважды.
 */
class WarehouseMarkupBonusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    /** Своё производство: ставка из настроек, от остатка сделки. */
    public function test_production_deal_uses_the_sale_rate(): void
    {
        $parts = PayrollService::dealBonus(870000, null, null, PayrollService::TYPE_PRODUCTION);

        $this->assertSame(1.0, $parts['rate']);
        $this->assertSame(8700.0, $parts['total']);
    }

    /** Перепродажа: своя ставка, вдвое выше. */
    public function test_resale_deal_uses_the_resale_rate(): void
    {
        $parts = PayrollService::dealBonus(870000, null, null, PayrollService::TYPE_RESALE);

        $this->assertSame(2.0, $parts['rate']);
        $this->assertSame(17400.0, $parts['total']);
    }

    /** Ставки владелец меняет в настройках, без правки кода. */
    public function test_rates_come_from_settings(): void
    {
        Setting::set('bonus_sale_percent', 1.5);
        Setting::set('bonus_resale_percent', 3);

        $this->assertSame(1.5, PayrollService::rateForType(PayrollService::TYPE_PRODUCTION));
        $this->assertSame(3.0, PayrollService::rateForType(PayrollService::TYPE_RESALE));
    }

    /** Ручной % по сделке и личный % сотрудника переопределяют ставку типа. */
    public function test_manual_and_personal_percent_override_the_rate(): void
    {
        $this->assertSame(5.0, PayrollService::dealBonus(100000, 5, 3, PayrollService::TYPE_RESALE)['rate']);
        $this->assertSame(3.0, PayrollService::dealBonus(100000, null, 3, PayrollService::TYPE_RESALE)['rate']);
    }

    /** Убыточная сделка бонуса не приносит: отрицательный бонус — удержание. */
    public function test_loss_making_deal_pays_no_bonus(): void
    {
        $this->assertSame(0.0, PayrollService::dealBonus(-50000)['total']);
    }

    /**
     * Наценка склада осталась ценой продажи товара, но отдельного бонуса
     * больше не даёт: за перепродажу платит ставка типа сделки.
     */
    public function test_markup_no_longer_creates_a_separate_bonus(): void
    {
        $company = Company::where('code', 'QT')->value('id');
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $manager->companies()->attach($company);

        ProductCategory::create(['name' => 'Плитка', 'slug' => 'plitka', 'is_active' => true]);
        $material = Material::create([
            'company_id' => $company, 'name' => 'Плитка', 'unit' => 'м²',
            'quantity' => 100, 'price' => 1000, 'markup_pct' => 50,
        ]);

        $deal = Deal::create([
            'company_id' => $company, 'number' => 'QT-1', 'name' => 'Сделка',
            'company_name' => 'Клиент', 'budget' => 1000000, 'status' => 'active',
            'deal_stage_id' => DealStage::where('is_won', true)->value('id'),
            'responsible_user_id' => $manager->id,
        ]);

        $this->actingAs($manager)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'material_id' => $material->id, 'qty' => 10, 'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        // Бонус пропорционален оплате — без денег клиента его нет вовсе.
        $invoice = \App\Models\Invoice::create([
            'invoiceable_type' => 'deal', 'invoiceable_id' => $deal->id,
            'number' => 'INV-1', 'amount' => 1000000, 'status' => 'paid',
        ]);
        \App\Models\Payment::create([
            'invoice_id' => $invoice->id, 'amount' => 1000000,
            'payment_date' => now()->toDateString(), 'payment_method' => 'bank',
        ]);

        // Остаток: 1 000 000 − налог 30 000 − списание 10 000 = 960 000 → 1%.
        $row = app(PayrollService::class)->perUser()->firstWhere('uid', $manager->id);
        $this->assertSame(9600.0, (float) $row['bonus']);
    }
}
