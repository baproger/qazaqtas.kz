<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModuleSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    public function test_index_pages_render_for_admin(): void
    {
        $user = $this->admin();

        foreach (['analytics.index', 'deals.index', 'projects.index', 'clients.index', 'departments.index'] as $name) {
            $this->actingAs($user)->get(route($name))->assertOk();
        }

        // Дашборд слит с Аналитикой: маршрут остался, но редиректит руководство туда.
        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('analytics.index'));
    }

    public function test_deal_creation_generates_number(): void
    {
        $user = $this->admin();
        $client = Client::create(['name' => 'Test Client', 'type' => 'legal']);

        $this->actingAs($user)->post(route('deals.store'), [
            'name' => 'Test Deal',
            'client_name' => 'Иван',
            'company_name' => 'ТОО Тест',
            'address' => 'Алматы, ул. Абая 1',
            'client_id' => $client->id,
            'budget' => 100000,
        ])->assertRedirect();

        $deal = Deal::first();
        $this->assertNotNull($deal);
        $this->assertMatchesRegularExpression('/^BAIA-\d{3,}$/', $deal->number);
        // Название сделки = название компании (поле «Название сделки» убрано из UI,
        // присланный 'name' игнорируется).
        $this->assertEquals('ТОО Тест', $deal->name);
    }

    public function test_moving_deal_to_won_stage_closes_it(): void
    {
        // Project creation is now explicit via "Отправить в цех" (see WorkflowTest);
        // reaching a won stage just closes the deal.
        $user = $this->admin();
        $wonStage = DealStage::where('is_won', true)->first();
        $deal = Deal::create([
            'number' => 'BAIA-TEST-1',
            'name' => 'Won Deal',
            'budget' => 500000,
            'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);

        $this->actingAs($user)
            ->patch(route('deals.stage', $deal), ['deal_stage_id' => $wonStage->id])
            ->assertRedirect();

        $deal->refresh();
        $this->assertEquals('active', $deal->status);
        $this->assertEquals(0, Project::where('deal_id', $deal->id)->count());
    }

    public function test_deal_show_renders(): void
    {
        $user = $this->admin();
        $deal = Deal::create([
            'number' => 'BAIA-TEST-2',
            'name' => 'Show Deal',
            'budget' => 100,
            'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);

        $this->actingAs($user)->get(route('deals.show', $deal))->assertOk();
    }
}
