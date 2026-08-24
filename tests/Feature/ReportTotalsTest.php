<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Отчёт идёт страницами, а итог — за весь период.
 *
 * Раньше в браузер уезжали все сделки выборки: на тысяче договоров это
 * мегабайты JSON. Теперь строк отдаётся сотня, но итог обязан остаться по
 * ВСЕЙ выборке — руководителю нужен итог периода, а не итог экрана.
 */
class ReportTotalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_is_limited_but_totals_cover_everything(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::where('code', 'QT')->value('id');
        $admin->companies()->attach($company);

        $stage = DealStage::where('is_won', true)->value('id');
        for ($i = 0; $i < 105; $i++) {
            $deal = Deal::create([
                'company_id' => $company, 'number' => 'R-'.$i, 'name' => 'Сделка',
                'company_name' => 'Клиент', 'budget' => 100000, 'status' => 'active',
                'deal_stage_id' => $stage, 'responsible_user_id' => $admin->id,
            ]);
            Expense::create([
                'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
                'amount' => 10000, 'date' => now()->toDateString(), 'status' => 'confirmed',
            ]);
        }

        $this->actingAs($admin)->get(route('reports.deals'))
            ->assertInertia(fn ($page) => $page
                ->has('rows', 100, fn ($row) => $row->etc())
                ->where('totals.count', 105)
                // Остаток сделки: 100 000 − налог 3 000 − расход 10 000 = 87 000.
                ->where('totals.budget', fn ($v) => (float) $v === 10500000.0)
                ->where('totals.remainder', fn ($v) => (float) $v === 9135000.0)
                ->etc());
    }
}
