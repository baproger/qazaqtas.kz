<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\ProjectStage;
use App\Models\ProjectStageLog;
use App\Models\User;
use App\Models\WorkshopScreen;
use App\Services\ProjectService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Тайминг этапов цеха + экран «Офис» (лидеры менеджеров). */
class StageTimingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    public function test_stage_timing_logged_per_stage(): void
    {
        $company = Company::firstOrCreate(['code' => 'QT'], ['name' => 'QT']);
        $s1 = ProjectStage::create(['company_id' => $company->id, 'name' => 'Формовка', 'order' => 1, 'type' => 'project', 'is_active' => true]);
        $s2 = ProjectStage::create(['company_id' => $company->id, 'name' => 'Жинау', 'order' => 2, 'type' => 'project', 'is_active' => true]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $deal = Deal::create(['number' => 'QT-001', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 1, 'status' => 'active', 'company_id' => $company->id, 'deal_stage_id' => DealStage::orderBy('order')->first()->id]);
        $project = app(ProjectService::class)->createFromDeal($deal);

        // Вход в цех — таймер первого этапа открыт.
        $open = ProjectStageLog::where('project_id', $project->id)->whereNull('left_at')->get();
        $this->assertCount(1, $open);
        $this->assertSame('Формовка', $open->first()->stage_name);

        // «Далее» — старый таймер закрыт с длительностью, новый открыт.
        $this->actingAs($admin)->patch(route('projects.advance', $project->id));
        $logs = ProjectStageLog::where('project_id', $project->id)->orderBy('entered_at')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertNotNull($logs[0]->left_at);
        $this->assertNotNull($logs[0]->duration_seconds);
        $this->assertSame('Жинау', $logs[1]->stage_name);
        $this->assertNull($logs[1]->left_at);

        // История таймингов видна на карточке заказа.
        $this->actingAs($admin)->get(route('projects.show', $project->id))
            ->assertInertia(fn (Assert $p) => $p->has('stageLogs', 2)->where('stageLogs.1.open', true));
    }

    /**
     * Экран «Офис»: лидер — тот, у кого сделки ДОШЛИ до оплаты, а не тот, кто
     * завёл их больше. Количеством лидером не стать.
     */
    public function test_office_leader_by_won_deals(): void
    {
        $company = Company::firstOrCreate(['code' => 'QT'], ['name' => 'QT']);
        $first = DealStage::orderBy('order')->first()->id;
        $wonStage = DealStage::where('is_won', true)->first() ?? DealStage::orderBy('order', 'desc')->first();

        $deal = fn (User $u, string $number, int $stage) => Deal::create([
            'number' => $number, 'name' => 'X', 'company_name' => 'Т', 'client_name' => 'И',
            'budget' => 1000000, 'status' => 'active', 'company_id' => $company->id,
            'deal_stage_id' => $stage, 'responsible_user_id' => $u->id,
        ]);

        // A: завёл 2 сделки, одну довёл до оплаты — лидер.
        $a = User::factory()->create(['name' => 'Выигрывает']);
        $a->assignRole('manager');
        $deal($a, 'QT-001', $wonStage->id);
        $deal($a, 'QT-002', $first);

        // B: завёл 3 сделки, ни одна не оплачена.
        $b = User::factory()->create(['name' => 'Количество']);
        $b->assignRole('manager');
        foreach ([3, 4, 5] as $i) {
            $deal($b, 'QT-00'.$i, $first);
        }

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->post(route('workshopScreens.upsert'), ['company_id' => $company->id, 'kind' => 'office'])->assertRedirect();
        $code = WorkshopScreen::where('kind', 'office')->firstOrFail()->code;

        auth()->logout();
        $this->post(route('screen.enter'), ['code' => $code]);
        $this->get(route('screen.show'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Screen/Office')
            ->where('leader.name', 'Выигрывает')
            ->where('managers.0.won', 1)
            ->where('managers.0.total', 2)
            ->where('managers.0.conversion', 50)
            ->where('managers.1.name', 'Количество')
            ->where('managers.1.won', 0)
            ->where('managers.1.total', 3));

        // Фильтр месяца: в прошлом месяце сделок не было — оплаченных 0.
        $this->get(route('screen.show', ['month' => now()->subMonthNoOverflow()->format('Y-m')]))
            ->assertInertia(fn (Assert $p) => $p->where('managers.0.won', 0));
    }
}
