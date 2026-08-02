<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OverdueListTest extends TestCase
{
    use RefreshDatabase;

    public function test_esf_and_won_hidden_but_act_shown_in_overdue(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $first = DealStage::orderBy('order')->first();
        $act = DealStage::create(['name' => 'Акт утверждение', 'type' => 'sale', 'order' => 90, 'is_active' => true, 'stage_type' => 'act', 'checklist' => []]);
        $esf = DealStage::create(['name' => 'ЭСФ', 'type' => 'sale', 'order' => 91, 'is_active' => true, 'stage_type' => 'esf', 'checklist' => []]);
        $won = DealStage::where('is_won', true)->first();

        $make = fn (string $n, int $stageId) => Deal::create([
            'number' => $n, 'name' => 'ТОО', 'company_name' => 'ТОО', 'client_name' => 'товар',
            'budget' => 100, 'status' => 'active', 'deadline' => now()->subDays(5), 'deal_stage_id' => $stageId,
        ]);
        $make('D-1', $first->id);  // просрочена — в списке
        $make('D-2', $act->id);    // Акт утверждение — ТОЖЕ просрочка (в списке)
        $make('D-3', $esf->id);    // ЭСФ — не просрочка
        $make('D-4', $won->id);    // Оплата успешно — не просрочка

        $this->actingAs($admin)->get(route('deals.overdue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Deals/Overdue')
                ->has('deals', 2)
                ->has('projects', 0));
    }
}
