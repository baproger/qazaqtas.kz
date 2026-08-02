<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\ProjectStage;
use App\Models\User;
use App\Models\WorkshopScreen;
use App\Services\ProjectService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** ТВ-экран цеха: код открывает канбан ТОЛЬКО своего цеха, без логина. */
class WorkshopScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    public function test_code_opens_only_its_workshop(): void
    {
        $company = Company::firstOrCreate(['code' => 'BAIA'], ['name' => 'BAIA']);
        ProjectStage::create(['company_id' => $company->id, 'workshop' => 'Металл цех', 'name' => 'Кесу М', 'order' => 1, 'type' => 'project', 'is_active' => true]);
        ProjectStage::create(['company_id' => $company->id, 'workshop' => 'Ағаш цех', 'name' => 'Кесу А', 'order' => 1, 'type' => 'project', 'is_active' => true]);

        // Заказ в Ағаш цехе.
        $deal = Deal::create(['number' => 'BAIA-001', 'name' => 'X', 'company_name' => 'ТОО Клиент', 'client_name' => 'И', 'budget' => 1, 'status' => 'active', 'company_id' => $company->id, 'deal_stage_id' => DealStage::orderBy('order')->first()->id]);
        app(ProjectService::class)->createFromDeal($deal, 'Ағаш цех');

        // Админ выдаёт код экрану «Металл цех».
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->post(route('workshopScreens.upsert'), ['company_id' => $company->id, 'workshop' => 'Металл цех'])->assertRedirect();
        $code = WorkshopScreen::where('workshop', 'Металл цех')->firstOrFail()->code;

        // Неверный код — не пускает.
        $this->post(route('screen.enter'), ['code' => '000000'])->assertSessionHasErrors('code');

        // Верный код (без логина!) — экран Металл цеха: свой этап, ЧУЖОЙ заказ не виден.
        $this->post(route('screen.enter'), ['code' => $code])->assertRedirect(route('screen.show'));
        $this->get(route('screen.show'))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->component('Screen/Workshop')
            ->where('screen.workshop', 'Металл цех')
            ->where('stages.0.name', 'Кесу М')
            ->has('stages', 1)
            ->has('projects', 0));

        // «Новый код» отключает экраны со старым кодом.
        $this->actingAs($admin)->post(route('workshopScreens.upsert'), ['company_id' => $company->id, 'workshop' => 'Металл цех']);
        auth()->logout();
        $this->get(route('screen.show'))->assertInertia(fn (Assert $p) => $p->component('Screen/Enter'));
    }
}
