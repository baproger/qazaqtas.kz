<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Гейт «Дизайн и расчет»: дизайнер видит сделки в списке, получает задачу и
 * уведомление при входе сделки на этап, и без его подтверждения сделка
 * дальше не идёт.
 */
class DesignerGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_designer_sees_deals_gets_notified_and_gates_transition(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $designer = User::factory()->create();
        $designer->assignRole('designer');
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $contract = DealStage::create(['name' => 'Договор', 'order' => 1, 'is_active' => true, 'stage_type' => 'contract']);
        $design = DealStage::create(['name' => 'Дизайн и расчет', 'order' => 2, 'is_active' => true, 'stage_type' => 'design',
            'gate_task_title' => 'Подтвердить дизайн и расчет', 'gate_task_role' => 'designer', 'gate_task_days' => 3]);
        $next = DealStage::create(['name' => 'Закуп', 'order' => 3, 'is_active' => true, 'stage_type' => 'shop_gate']);
        // Полная воронка: без act/esf/won позиционные фолбэки приняли бы
        // «Дизайн» за «Акт» и заблокировали переход не-бухгалтеру.
        DealStage::create(['name' => 'Акт утверждение', 'order' => 4, 'is_active' => true, 'stage_type' => 'act']);
        DealStage::create(['name' => 'ЭСФ', 'order' => 5, 'is_active' => true, 'stage_type' => 'esf']);
        DealStage::create(['name' => 'Оплата успешно', 'order' => 6, 'is_active' => true, 'stage_type' => 'payment_won', 'is_won' => true]);

        $deal = Deal::create(['number' => 'T-1', 'name' => 'X', 'company_name' => 'ТОО Дизайн', 'client_name' => 'И',
            'budget' => 100, 'status' => 'active', 'deal_stage_id' => $contract->id, 'responsible_user_id' => $manager->id]);

        // Сделка входит на «Дизайн и расчет» → дизайнеру задача + уведомление.
        app(StageTransitionService::class)->moveToStage($deal->fresh(), $design);
        $this->assertSame(1, $deal->tasks()->where('assignee_id', $designer->id)->count());
        $this->assertSame(1, $designer->notifications()->count());

        // Дизайнер ВИДИТ сделку в общем списке (раньше список был пуст).
        $page = $this->actingAs($designer)->get(route('deals.index'));
        $page->assertOk();
        $this->assertTrue(collect($page->viewData('page')['props']['deals'])->contains(fn ($d) => $d['id'] === $deal->id));

        // Пока дизайнер не подтвердил — дальше нельзя.
        try {
            app(StageTransitionService::class)->moveToStage($deal->fresh(), $next);
            $this->fail('Сделка не должна пройти без подтверждения дизайнера.');
        } catch (\Illuminate\Validation\ValidationException) {
            // ожидаемо: гейт держит
        }

        // Дизайнер ставит галочку → сделка проходит дальше.
        $this->actingAs($designer)->patch(route('deals.stageTask', $deal->id))->assertRedirect();
        app(StageTransitionService::class)->moveToStage($deal->fresh(), $next);
        $this->assertSame($next->id, $deal->fresh()->deal_stage_id);

        // Сделка ушла с «Дизайна» — из списка дизайнера она пропадает
        // (он видит ТОЛЬКО сделки на своём гейт-этапе).
        $page = $this->actingAs($designer)->get(route('deals.index'));
        $this->assertFalse(collect($page->viewData('page')['props']['deals'])->contains(fn ($d) => $d['id'] === $deal->id));
    }
}
