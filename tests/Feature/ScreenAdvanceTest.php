<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\WorkshopScreen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Далее» с ТВ-экрана цеха: двигает заказ на следующий этап по коду экрана
 * в сессии; чужой цех и чужая сессия — 403.
 */
class ScreenAdvanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_screen_advances_own_workshop_order_only(): void
    {
        $s1 = ProjectStage::create(['name' => 'Кесу', 'order' => 1, 'is_active' => true, 'workshop' => 'Металл цех']);
        $s2 = ProjectStage::create(['name' => 'Тесу', 'order' => 2, 'is_active' => true, 'workshop' => 'Металл цех']);
        $agashStage = ProjectStage::create(['name' => 'Кесу', 'order' => 1, 'is_active' => true, 'workshop' => 'Ағаш цех']);

        $metal = Project::create(['number' => 'PRJ-1', 'name' => 'Стол', 'workshop' => 'Металл цех', 'project_stage_id' => $s1->id, 'status' => 'active']);
        $agash = Project::create(['number' => 'PRJ-2', 'name' => 'Шкаф', 'workshop' => 'Ағаш цех', 'project_stage_id' => $agashStage->id, 'status' => 'active']);

        $screen = WorkshopScreen::create(['workshop' => 'Металл цех', 'kind' => 'workshop', 'code' => '123456', 'is_active' => true]);
        $session = ['workshop_screen_id' => $screen->id, 'workshop_screen_code' => '123456'];

        // Свой цех — этап двигается.
        $this->withSession($session)->post(route('screen.advanceProject', $metal->id))->assertRedirect();
        $this->assertSame($s2->id, $metal->fresh()->project_stage_id);

        // Последний этап — дальше нельзя (Готово только в системе).
        $this->withSession($session)->post(route('screen.advanceProject', $metal->id))->assertSessionHas('error');
        $this->assertSame($s2->id, $metal->fresh()->project_stage_id);

        // Чужой цех — 403.
        $this->withSession($session)->post(route('screen.advanceProject', $agash->id))->assertForbidden();

        // Без кода экрана (нет сессии) — 403.
        $this->flushSession();
        $this->post(route('screen.advanceProject', $metal->id))->assertForbidden();
    }

    public function test_screen_completes_order_only_from_last_stage(): void
    {
        $s1 = ProjectStage::create(['name' => 'Кесу', 'order' => 1, 'is_active' => true, 'workshop' => 'Металл цех']);
        $s2 = ProjectStage::create(['name' => 'Отправка', 'order' => 2, 'is_active' => true, 'workshop' => 'Металл цех']);
        $dealStage = \App\Models\DealStage::create(['name' => 'Закуп', 'order' => 1, 'is_active' => true]);
        $logistics = \App\Models\DealStage::create(['name' => 'Логистика', 'order' => 2, 'is_active' => true, 'stage_type' => 'logistics']);
        $deal = \App\Models\Deal::create(['number' => 'T-1', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 100, 'status' => 'closed', 'deal_stage_id' => $dealStage->id]);
        $project = Project::create(['number' => 'PRJ-3', 'name' => 'Стол', 'deal_id' => $deal->id, 'workshop' => 'Металл цех', 'project_stage_id' => $s1->id, 'status' => 'active']);

        $screen = WorkshopScreen::create(['workshop' => 'Металл цех', 'kind' => 'workshop', 'code' => '654321', 'is_active' => true]);
        $session = ['workshop_screen_id' => $screen->id, 'workshop_screen_code' => '654321'];

        // Не последний этап — «Готово» недоступно.
        $this->withSession($session)->post(route('screen.completeProject', $project->id))->assertForbidden();

        // С «Отправки» — заказ завершён, сделка вернулась на «Логистику».
        $project->update(['project_stage_id' => $s2->id]);
        $this->withSession($session)->post(route('screen.completeProject', $project->id))->assertRedirect();
        $this->assertSame('completed', $project->fresh()->status);
        $this->assertSame($logistics->id, $deal->fresh()->deal_stage_id);
        $this->assertSame('active', $deal->fresh()->status);
    }
}
