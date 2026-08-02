<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Управление чатами: корзина (восстановление/стирание), участники группы,
 * изоляция групп по фирмам BAIA/ASU, «кто прочитал», state-поллинг.
 */
class ChatManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('admin');

        return $u;
    }

    private function group(array $attrs = []): Chat
    {
        return Chat::create(array_merge(['type' => 'group', 'name' => 'Группа', 'is_active' => true], $attrs));
    }

    public function test_deleted_chat_goes_to_trash_and_can_be_restored(): void
    {
        $admin = $this->admin();
        $chat = $this->group();
        $chat->participants()->attach($admin->id, ['joined_at' => now()]);
        $chat->messages()->create(['user_id' => $admin->id, 'message' => 'история']);

        // Удаление — мягкое: сообщения остаются.
        $this->actingAs($admin)->delete(route('chat.destroy', $chat->id))->assertRedirect();
        $this->assertSoftDeleted('chats', ['id' => $chat->id]);
        $this->assertDatabaseHas('chat_messages', ['chat_id' => $chat->id]);

        // Восстановление возвращает чат со всей историей.
        $this->actingAs($admin)->post(route('chat.restore', $chat->id))->assertRedirect();
        $this->assertNull($chat->fresh()->deleted_at);

        // Стирание навсегда — чата и сообщений больше нет.
        $this->actingAs($admin)->delete(route('chat.destroy', $chat->id));
        $this->actingAs($admin)->delete(route('chat.force', $chat->id))->assertRedirect();
        $this->assertDatabaseMissing('chats', ['id' => $chat->id]);
        $this->assertDatabaseMissing('chat_messages', ['chat_id' => $chat->id]);
    }

    public function test_trash_is_admin_only(): void
    {
        $this->admin();
        $emp = User::factory()->create();
        $emp->assignRole('employee');
        $chat = $this->group();

        $this->actingAs($emp)->delete(route('chat.destroy', $chat->id))->assertForbidden();
        $chat->delete();
        $this->actingAs($emp)->post(route('chat.restore', $chat->id))->assertForbidden();
        $this->actingAs($emp)->delete(route('chat.force', $chat->id))->assertForbidden();
    }

    public function test_admin_adds_and_removes_group_member(): void
    {
        $admin = $this->admin();
        $emp = User::factory()->create();
        $emp->assignRole('employee');
        $chat = $this->group();

        $this->actingAs($admin)->post(route('chat.members.add', $chat->id), ['user_id' => $emp->id])->assertRedirect();
        $this->assertTrue($chat->participants()->where('users.id', $emp->id)->exists());

        $this->actingAs($admin)->delete(route('chat.members.remove', [$chat->id, $emp->id]))->assertRedirect();
        $this->assertFalse($chat->participants()->where('users.id', $emp->id)->exists());

        // Обычный сотрудник участниками не управляет.
        $this->actingAs($emp)->post(route('chat.members.add', $chat->id), ['user_id' => $emp->id])->assertForbidden();
    }

    public function test_company_group_hidden_from_other_firm_employee(): void
    {
        $this->admin();
        $baia = Company::create(['name' => 'BAIA', 'code' => 'baia', 'is_active' => true]);
        $asu = Company::create(['name' => 'ASU', 'code' => 'asu', 'is_active' => true]);

        $asuWorker = User::factory()->create();
        $asuWorker->assignRole('employee');
        $asuWorker->companies()->sync([$asu->id]);

        // Группа BAIA, сотрудник ASU — участник (добавили по ошибке).
        $chat = $this->group(['company_id' => $baia->id, 'name' => 'BAIA цех']);
        $chat->participants()->attach($asuWorker->id, ['joined_at' => now()]);

        // В списке чатов группы BAIA нет…
        $page = $this->actingAs($asuWorker)->get(route('chat.index'));
        $page->assertOk();
        $chats = collect($page->viewData('page')['props']['chats']);
        $this->assertFalse($chats->contains(fn ($c) => $c['id'] === $chat->id));

        // …и по прямой ссылке сообщения недоступны.
        $this->actingAs($asuWorker)->get(route('chat.messages', $chat->id))->assertForbidden();

        // Сотрудник BAIA группу видит и читает.
        $baiaWorker = User::factory()->create();
        $baiaWorker->assignRole('employee');
        $baiaWorker->companies()->sync([$baia->id]);
        $chat->participants()->attach($baiaWorker->id, ['joined_at' => now()]);
        $this->actingAs($baiaWorker)->get(route('chat.messages', $chat->id))->assertOk();
    }

    public function test_messages_payload_contains_reads_and_state_endpoint_works(): void
    {
        $admin = $this->admin();
        $emp = User::factory()->create();
        $emp->assignRole('employee');
        $chat = $this->group();
        $chat->participants()->attach([$admin->id => ['joined_at' => now()], $emp->id => ['joined_at' => now()]]);
        $msg = $chat->messages()->create(['user_id' => $admin->id, 'message' => 'проверка']);

        // Сотрудник прочитал чат — отметка попадает в reads.
        $this->actingAs($emp)->post(route('chat.read', $chat->id))->assertOk();
        $reads = $this->actingAs($admin)->get(route('chat.messages', $chat->id))->assertOk()->json('reads');
        $this->assertEquals($msg->id, (int) $reads[$emp->id]);

        // state: непрочитанные и последнее сообщение для бейджей/звука.
        $state = $this->actingAs($admin)->get(route('chat.state'))->assertOk()->json('state');
        $this->assertArrayHasKey((string) $chat->id, $state);
        $this->assertEquals('проверка', $state[$chat->id]['last']['text']);
    }

    public function test_admin_deletes_personal_chat_but_not_deal_channel(): void
    {
        $admin = $this->admin();

        // Личный чат — можно в корзину.
        $personal = Chat::create(['type' => 'personal', 'is_active' => true]);
        $this->actingAs($admin)->delete(route('chat.destroy', $personal->id))->assertRedirect();
        $this->assertSoftDeleted('chats', ['id' => $personal->id]);

        // Канал сделки — нельзя (живёт со сделкой).
        $stage = \App\Models\DealStage::create(['name' => 'Этап', 'order' => 1, 'is_active' => true]);
        $deal = \App\Models\Deal::create(['number' => 'T-1', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 100, 'status' => 'active', 'deal_stage_id' => $stage->id]);
        $dealChat = Chat::create(['type' => 'group', 'deal_id' => $deal->id, 'name' => 'Сделка', 'is_active' => true]);
        $this->actingAs($admin)->delete(route('chat.destroy', $dealChat->id))->assertForbidden();
    }

    public function test_group_created_with_company_binding(): void
    {
        $admin = $this->admin();
        $baia = Company::create(['name' => 'BAIA', 'code' => 'baia', 'is_active' => true]);

        $this->actingAs($admin)->post(route('chat.store'), [
            'type' => 'group', 'name' => 'Отдел продаж BAIA', 'company_id' => $baia->id, 'participants' => [],
        ])->assertRedirect();

        $this->assertDatabaseHas('chats', ['name' => 'Отдел продаж BAIA', 'company_id' => $baia->id]);
    }
}
