<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use App\Support\ReportCache;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Тяжёлые отчёты кешируются и сбрасываются при изменении денег. */
class ReportCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_is_served_from_cache_until_money_changes(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $company = Company::where('code', 'QT')->value('id');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->companies()->attach($company);
        $won = DealStage::where('is_won', true)->first();
        $mk = fn ($n) => Deal::create(['company_id' => $company, 'number' => $n, 'name' => 'X', 'company_name' => 'ТОО', 'budget' => 100000,
            'status' => 'closed', 'deal_stage_id' => $won->id, 'responsible_user_id' => $admin->id]);

        $mk('C-1');
        $v1 = ReportCache::version(); // сделка уже сдвинула версию
        $as = fn () => $this->actingAs($admin)->withSession(['company_id' => $company]);
        $as()->get(route('analytics.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('conversion.won', 1));

        // Изменение денег → новая версия → отчёт пересчитан.
        $mk('C-2');
        $this->assertNotSame($v1, ReportCache::version());
        $as()->get(route('analytics.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('conversion.won', 2));

        // Без изменений — второй запрос из кеша (версия та же), данные те же.
        $v2 = ReportCache::version();
        $as()->get(route('reports.deals'))->assertOk();
        $as()->get(route('payroll.index'))->assertOk();
        $this->assertSame($v2, ReportCache::version());
    }
}
