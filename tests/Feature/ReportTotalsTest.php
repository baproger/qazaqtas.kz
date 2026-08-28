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
                // Итоговая маржа — ТОЙ ЖЕ формулой, что в строках таблицы:
                // (остаток + налог) / бюджет = (87 000 + 3 000) / 100 000 = 90%.
                // Раньше итог считался «чистая прибыль / бюджет», и колонка не
                // сходилась сама с собой — цифра под таблицей спорила со строками.
                ->where('totals.margin', fn ($v) => (float) $v === 90.0)
                ->where('rows.0.margin', fn ($v) => (float) $v === 90.0)
                ->etc());
    }

    /**
     * Маржа сделки — одно число, где бы её ни смотрели.
     *
     * Карточка сделки и Сводный отчёт считают её одним методом
     * (PayrollService::marginPct). Разойдись они — менеджер и руководитель
     * спорили бы о здоровье одной и той же сделки.
     */
    public function test_the_deal_card_shows_the_same_margin_as_the_report(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $company = Company::where('code', 'QT')->value('id');
        $admin->companies()->attach($company);

        $deal = Deal::create([
            'company_id' => $company, 'number' => 'M-1', 'name' => 'Сделка',
            'company_name' => 'Клиент', 'client_name' => 'Плитка', 'budget' => 400000,
            'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->value('id'),
            'responsible_user_id' => $admin->id,
        ]);
        Expense::create([
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'amount' => 100000, 'date' => now()->toDateString(), 'status' => 'confirmed',
        ]);

        // (400 000 − 100 000) / 400 000 = 75%.
        $this->actingAs($admin)->get(route('deals.show', $deal))
            ->assertInertia(fn ($page) => $page->where('profit.margin', fn ($v) => (float) $v === 75.0)->etc());

        $this->actingAs($admin)->get(route('reports.deals'))
            ->assertInertia(fn ($page) => $page
                ->where('rows.0.margin', fn ($v) => (float) $v === 75.0)
                ->where('totals.margin', fn ($v) => (float) $v === 75.0)
                ->etc());
    }
}
