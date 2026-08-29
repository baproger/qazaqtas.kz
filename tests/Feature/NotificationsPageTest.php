<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use App\Notifications\DealStageChanged;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_lists_notifications_with_details_and_deal_events(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $company = Company::where('code', 'QT')->value('id');
        $mgr = User::factory()->create();
        $mgr->assignRole('manager');
        $mgr->companies()->attach($company);

        $stages = DealStage::funnel($company);
        $deal = Deal::create(['company_id' => $company, 'number' => 'N-1', 'name' => 'X', 'company_name' => 'ТОО', 'budget' => 1,
            'status' => 'active', 'deal_stage_id' => $stages[0]->id, 'responsible_user_id' => $mgr->id]);
        $this->actingAs($mgr);
        app(StageTransitionService::class)->moveToStage($deal, $stages[1]);
        $mgr->notify(new DealStageChanged($deal, 'Договор'));

        $this->actingAs($mgr)->withSession(['company_id' => $company])->get(route('notifications.index'))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Notifications/Index')
                ->where('unread', fn ($u) => $u >= 1)
                ->where('notifications.data.0.typeLabel', 'Этап сделки')
                ->where('notifications.data.0.url', route('deals.show', $deal->id))
                ->where('events', fn ($ev) => collect($ev)->contains(fn ($e) => $e['kind'] === 'stage' && $e['deal']['number'] === 'N-1')));

        $this->actingAs($mgr)->get(route('notifications.index', ['only' => 'unread', 'type' => 'robot']))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('notifications.data', 0));
    }
}
