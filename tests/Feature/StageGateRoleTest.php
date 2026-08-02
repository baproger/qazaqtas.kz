<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Гейт «Дизайн и расчет»: задача и уведомление — только дизайнерам; галочку
 * ставит только дизайнер (или админ); до подтверждения сделка не идёт дальше.
 */
class StageGateRoleTest extends TestCase
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

    /** Сделка заводится на этап с гейтом дизайнера (гейт-задача создаётся). */
    private function dealOnDesignGate(): Deal
    {
        [$first, $design] = DealStage::orderBy('order')->take(2)->get();
        $design->update(['stage_type' => 'design', 'gate_task_title' => 'Подтвердить дизайн и расчет', 'gate_task_role' => 'designer', 'gate_task_days' => 3]);

        $mgr = $this->user('manager');
        $deal = Deal::create(['number' => 'BAIA-001', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 100000, 'status' => 'active', 'deal_stage_id' => $first->id, 'responsible_user_id' => $mgr->id]);
        app(StageTransitionService::class)->moveToStage($deal, $design->fresh());

        return $deal->fresh();
    }

    public function test_gate_task_goes_to_designers_only(): void
    {
        $designer = $this->user('designer');
        $this->user('financist'); // не должен получить задачу
        $deal = $this->dealOnDesignGate();

        $tasks = $deal->tasks()->where('title', 'like', 'Подтвердить дизайн и расчет%')->get();
        $this->assertCount(1, $tasks);
        $this->assertSame($designer->id, $tasks->first()->assignee_id);
    }

    public function test_deal_blocked_until_designer_confirms(): void
    {
        $designer = $this->user('designer');
        $admin = $this->user('admin');
        $deal = $this->dealOnDesignGate();
        $stageBefore = $deal->deal_stage_id;

        // Гейт открыт — даже админ не может двинуть сделку дальше.
        $this->actingAs($admin)->patch(route('deals.advance', $deal->id))->assertSessionHas('error');
        $this->assertSame($stageBefore, $deal->fresh()->deal_stage_id);

        // Дизайнер подтвердил — сделка идёт на следующий этап («Закуп»).
        $this->actingAs($designer)->patch(route('deals.stageTask', $deal->id))->assertSessionHas('success');
        $this->actingAs($admin)->patch(route('deals.advance', $deal->id))->assertSessionHas('success');
        $this->assertNotSame($stageBefore, $deal->fresh()->deal_stage_id);
    }

    public function test_only_designer_or_admin_confirms_design_gate(): void
    {
        $this->user('designer'); // чтобы гейт-задача существовала
        $deal = $this->dealOnDesignGate();

        // Менеджер (ответственный) и даже бухгалтер — нельзя: гейт дизайнерский.
        $this->actingAs($deal->responsible)->patch(route('deals.stageTask', $deal->id))->assertForbidden();
        $this->actingAs($this->user('financist'))->patch(route('deals.stageTask', $deal->id))->assertForbidden();

        // Админ — можно (страховка, чтобы система не встала без дизайнера).
        $this->actingAs($this->user('admin'))->patch(route('deals.stageTask', $deal->id))->assertSessionHas('success');
    }

    public function test_financist_can_create_deal(): void
    {
        $fin = $this->user('financist');
        $this->actingAs($fin)->post(route('deals.store'), [
            'company_name' => 'ТОО Клиент', 'client_name' => 'Иван', 'address' => 'ул. Тест 1', 'budget' => 100000,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, Deal::count());
    }

    public function test_designer_can_view_deal_but_not_edit(): void
    {
        $designer = $this->user('designer');
        $deal = $this->dealOnDesignGate();

        $this->actingAs($designer)->get(route('deals.show', $deal->id))->assertOk();
        $this->actingAs($designer)->put(route('deals.update', $deal->id), [
            'company_name' => 'Взлом', 'client_name' => 'И', 'address' => 'ул. Тест 1', 'budget' => 1,
        ])->assertForbidden();
    }
}
