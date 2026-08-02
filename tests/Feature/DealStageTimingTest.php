<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\DealStageLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * История этапов сделки (deal_stage_logs): каждый шаг фиксируется —
 * вход на этап, уход, длительность; уход в цех останавливает таймер.
 */
class DealStageTimingTest extends TestCase
{
    use RefreshDatabase;

    private function deal(): array
    {
        $s1 = DealStage::create(['name' => 'Договор', 'order' => 1, 'is_active' => true]);
        $s2 = DealStage::create(['name' => 'Логистика', 'order' => 2, 'is_active' => true]);
        $deal = Deal::create(['number' => 'T-1', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 100, 'status' => 'active', 'deal_stage_id' => $s1->id]);

        return [$deal, $s1, $s2];
    }

    public function test_stage_changes_are_logged_with_duration(): void
    {
        [$deal, $s1, $s2] = $this->deal();

        // Создание сделки открыло таймер первого этапа.
        $open = DealStageLog::where('deal_id', $deal->id)->whereNull('left_at')->first();
        $this->assertNotNull($open);
        $this->assertSame('Договор', $open->stage_name);

        // Смена этапа: старый закрыт (с длительностью), новый открыт.
        $deal->update(['deal_stage_id' => $s2->id]);
        $closed = DealStageLog::where('deal_id', $deal->id)->whereNotNull('left_at')->first();
        $this->assertSame('Договор', $closed->stage_name);
        $this->assertNotNull($closed->duration_seconds);
        $this->assertSame('Логистика', DealStageLog::where('deal_id', $deal->id)->whereNull('left_at')->first()->stage_name);
    }

    public function test_workshop_and_cancel_close_the_open_log(): void
    {
        [$deal] = $this->deal();

        // Ушла в цех (status=closed) — таймер этапа остановлен.
        $deal->update(['status' => 'closed']);
        $this->assertSame(0, DealStageLog::where('deal_id', $deal->id)->whereNull('left_at')->count());

        // Вернулась в работу — таймер снова тикает на текущем этапе.
        $deal->update(['status' => 'active']);
        $this->assertSame(1, DealStageLog::where('deal_id', $deal->id)->whereNull('left_at')->count());
    }
}
