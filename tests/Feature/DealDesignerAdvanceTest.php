<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Далее →» у дизайнера: со СВОЕГО гейт-этапа «Дизайн и расчет» (stage_type=design)
 * он сам отправляет сделку на следующий этап; с других этапов и произвольные
 * перестановки этапов ему запрещены.
 */
class DealDesignerAdvanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    private function designer(): User
    {
        $u = User::factory()->create();
        $u->assignRole('designer');

        return $u;
    }

    private function deal(int $stageId): Deal
    {
        return Deal::create(['number' => 'D-'.uniqid(), 'name' => 'X', 'company_name' => 'ТОО',
            'client_name' => 'И', 'budget' => 100000, 'status' => 'active', 'deal_stage_id' => $stageId]);
    }

    public function test_designer_advances_deal_from_design_stage_to_next(): void
    {
        $stages = DealStage::funnel(null)->values();
        $stages[1]->update(['stage_type' => 'design']);
        $deal = $this->deal($stages[1]->id);

        $this->actingAs($this->designer())->patch(route('deals.advance', $deal))->assertRedirect();

        $this->assertSame($stages[2]->id, $deal->fresh()->deal_stage_id);
    }

    public function test_designer_cannot_advance_from_other_stage(): void
    {
        $stages = DealStage::funnel(null)->values();
        $stages[1]->update(['stage_type' => 'design']);
        $deal = $this->deal($stages[0]->id); // сделка НЕ на этапе дизайна

        $this->actingAs($this->designer())->patch(route('deals.advance', $deal))->assertForbidden();
    }

    public function test_designer_cannot_move_deal_to_arbitrary_stage(): void
    {
        $stages = DealStage::funnel(null)->values();
        $stages[1]->update(['stage_type' => 'design']);
        $deal = $this->deal($stages[1]->id);

        // Перетащить на произвольный этап (через один) — нельзя, только «Далее».
        $this->actingAs($this->designer())
            ->patch(route('deals.stage', $deal), ['deal_stage_id' => $stages[3]->id])
            ->assertForbidden();
    }
}
