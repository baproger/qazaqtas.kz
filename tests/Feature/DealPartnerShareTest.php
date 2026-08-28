<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use App\Services\PayrollService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Доля партнёра сделки: вводится только % (deals.partner_pct), сумма = % × сумма
 * договора и вычитается из остатка во всех расчётах (маржа, бонус, отчёты).
 */
class DealPartnerShareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    public function test_partner_share_reduces_remainder_and_bonus(): void
    {
        $admin = $this->user('admin');
        $mgr = $this->user('manager');
        $wonStage = DealStage::where('is_won', true)->first()->id;
        // 1 000 000 − налог 3% (30 000) − партнёр 10% (100 000) = остаток 870 000.
        Deal::create(['number' => 'D-P1', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И',
            'budget' => 1000000, 'partner_pct' => 10, 'status' => 'closed',
            'deal_stage_id' => $wonStage, 'responsible_user_id' => $mgr->id]);

        // Маржа (до налога) = (870000 + 30000)/1000000 = 90% → ставка 15%;
        // оплат нет → бонус к выплате 0, но остаток на карточке 870 000.
        $this->actingAs($admin)->get(route('payroll.index'))
            ->assertInertia(fn (Assert $p) => $p->component('Payroll/Index')
                ->where('rows', fn ($rows) => collect($rows)->contains(fn ($r) => $r['uid'] === $mgr->id
                    && $r['remainder'] == 870000.0)));

        $this->assertEquals(100000.0, PayrollService::partnerSum(1000000, 10));
        $this->assertEquals(0.0, PayrollService::partnerSum(1000000, null));
    }

    public function test_deal_card_shows_partner_in_profit(): void
    {
        $admin = $this->user('admin');
        $deal = Deal::create(['number' => 'D-P2', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И',
            'budget' => 500000, 'partner_pct' => 7.5, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id]);

        // 500000 − налог 15000 − партнёр 37500 = 447500.
        $this->actingAs($admin)->get(route('deals.show', $deal))
            ->assertInertia(fn (Assert $p) => $p->component('Deals/Show')
                ->where('profit.partner', 37500)
                ->where('profit.partnerPct', 7.5)
                ->where('profit.remainder', 447500));
    }

    /** Доля партнёра вводится в форме сделки и доезжает до записи. */
    public function test_creating_a_deal_keeps_partner_pct(): void
    {
        $mgr = $this->user('manager');
        $this->actingAs($mgr)->post(route('deals.store'), [
            'company_name' => 'Школа', 'client_name' => 'Парта', 'address' => 'Алматы',
            'budget' => 1600000, 'partner_pct' => 5,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(5.0, (float) Deal::firstOrFail()->partner_pct);
    }

    public function test_manager_can_set_partner_pct_on_own_deal(): void
    {
        $mgr = $this->user('manager');
        $deal = Deal::create(['number' => 'D-P3', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И',
            'budget' => 100000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id, 'responsible_user_id' => $mgr->id]);

        $this->actingAs($mgr)->put(route('deals.update', $deal), [
            'company_name' => 'ТОО', 'client_name' => 'И', 'address' => 'Алматы', 'budget' => 100000,
            'deal_stage_id' => $deal->deal_stage_id, 'responsible_user_id' => $mgr->id,
            'partner_pct' => 12.5,
        ])->assertSessionHasNoErrors();

        $this->assertEquals(12.5, (float) $deal->fresh()->partner_pct);
    }
}
