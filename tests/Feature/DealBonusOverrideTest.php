<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use App\Services\PayrollService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ручной % бонуса менеджера по сделке (deals.bonus_rate_override):
 * ставит финансист/админ; null = авто-ступень от маржи.
 */
class DealBonusOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_rate_overrides_margin_ladder(): void
    {
        // Маржа 50% → авто-ступень 15% от остатка 50 = 7.5; ручные 10% должны победить.
        $this->assertSame(7.5, PayrollService::marginBonus(100.0, 50.0, 0.0));
        $this->assertSame(5.0, PayrollService::marginBonus(100.0, 50.0, 0.0, 10.0));
        // Ручной 0% — бонуса нет вовсе.
        $this->assertSame(0.0, PayrollService::marginBonus(100.0, 50.0, 0.0, 0.0));
    }

    public function test_financist_sets_and_resets_rate_but_manager_cannot(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $fin = User::factory()->create();
        $fin->assignRole('financist');
        $mgr = User::factory()->create();
        $mgr->assignRole('manager');

        $stage = DealStage::create(['name' => 'Договор', 'order' => 1, 'is_active' => true]);
        $deal = Deal::create(['number' => 'T-1', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 1000, 'status' => 'active', 'deal_stage_id' => $stage->id, 'responsible_user_id' => $mgr->id]);

        // Финансист ставит ручные 10%.
        $this->actingAs($fin)->patch(route('deals.bonusRate', $deal->id), ['bonus_rate_override' => 10])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(10.0, (float) $deal->fresh()->bonus_rate_override);

        // Менеджер (даже ответственный по сделке) — не может.
        $this->actingAs($mgr)->patch(route('deals.bonusRate', $deal->id), ['bonus_rate_override' => 50])
            ->assertForbidden();

        // Сброс на авто: пустое значение → null.
        $this->actingAs($fin)->patch(route('deals.bonusRate', $deal->id), ['bonus_rate_override' => null])
            ->assertRedirect();
        $this->assertNull($deal->fresh()->bonus_rate_override);

        // Больше 100% — валидация не пустит.
        $this->actingAs($fin)->patch(route('deals.bonusRate', $deal->id), ['bonus_rate_override' => 150])
            ->assertSessionHasErrors('bonus_rate_override');
    }
}
