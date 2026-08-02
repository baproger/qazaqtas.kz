<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealChatManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    public function test_deal_has_its_own_chat_and_owner_can_post(): void
    {
        $mgr = $this->user('manager');
        $deal = Deal::create(['number' => 'D-1', 'name' => 'X', 'budget' => 1, 'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->first()->id, 'responsible_user_id' => $mgr->id]);

        $this->actingAs($mgr)->get(route('deals.show', $deal))->assertOk();
        $chat = Chat::where('deal_id', $deal->id)->first();
        $this->assertNotNull($chat);

        $this->actingAs($mgr)->post(route('chat.send', $chat), ['message' => 'Обсудим'])->assertRedirect();
        $this->actingAs($mgr)->getJson(route('chat.messages', $chat))->assertOk()->assertJsonFragment(['message' => 'Обсудим']);
    }

    public function test_cex_employee_cannot_read_deal_chat(): void
    {
        $mgr = $this->user('manager');
        $emp = $this->user('employee');
        $deal = Deal::create(['number' => 'D-2', 'name' => 'X', 'budget' => 1, 'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->first()->id, 'responsible_user_id' => $mgr->id]);
        $chat = Chat::create(['deal_id' => $deal->id, 'type' => 'group', 'name' => 'Чат', 'is_active' => true]);

        $this->actingAs($emp)->getJson(route('chat.messages', $chat))->assertForbidden();
    }

    public function test_manager_cannot_open_general_analytics(): void
    {
        $mgr = $this->user('manager');
        $this->actingAs($mgr)->get(route('analytics.index'))->assertForbidden();
    }
}
