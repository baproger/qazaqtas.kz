<?php

namespace Tests\Feature;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('employee');
        return $u;
    }

    public function test_index_creates_global_chat_and_renders(): void
    {
        $u = $this->user();
        $this->actingAs($u)->get(route('chat.index'))->assertOk();
        $this->assertEquals(1, Chat::where('type', 'global')->count());
    }

    public function test_send_and_fetch_messages(): void
    {
        $u = $this->user();
        $this->actingAs($u)->get(route('chat.index')); // ensures global chat
        $chat = Chat::where('type', 'global')->first();

        $this->actingAs($u)->post(route('chat.send', $chat), ['message' => 'Привет команда'])->assertRedirect();

        $response = $this->actingAs($u)->getJson(route('chat.messages', $chat));
        $response->assertOk()->assertJsonFragment(['message' => 'Привет команда']);
    }

    public function test_non_participant_cannot_read_group_chat(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $owner = User::factory()->create();
        $owner->assignRole('admin');
        $outsider = User::factory()->create();
        $outsider->assignRole('employee');

        $this->actingAs($owner)->post(route('chat.store'), ['type' => 'group', 'name' => 'Приватный', 'participants' => []])->assertRedirect();
        $chat = Chat::where('type', 'group')->first();

        $this->actingAs($outsider)->getJson(route('chat.messages', $chat))->assertForbidden();
    }

    public function test_admin_can_rename_and_delete_group(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->post(route('chat.store'), ['type' => 'group', 'name' => 'Старое', 'participants' => []])->assertRedirect();
        $chat = Chat::where('type', 'group')->first();

        $this->actingAs($admin)->put(route('chat.update', $chat), ['name' => 'Новое', 'participants' => []])->assertRedirect();
        $this->assertEquals('Новое', $chat->fresh()->name);

        // Удаление теперь мягкое: чат уходит в корзину (восстановим при нужде).
        $this->actingAs($admin)->delete(route('chat.destroy', $chat))->assertRedirect();
        $this->assertSoftDeleted('chats', ['id' => $chat->id]);
    }

    public function test_employee_cannot_edit_or_delete_group(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $emp = User::factory()->create();
        $emp->assignRole('employee');

        $this->actingAs($admin)->post(route('chat.store'), ['type' => 'group', 'name' => 'Группа', 'participants' => [$emp->id]])->assertRedirect();
        $chat = Chat::where('type', 'group')->first();

        $this->actingAs($emp)->put(route('chat.update', $chat), ['name' => 'Взлом'])->assertForbidden();
        $this->actingAs($emp)->delete(route('chat.destroy', $chat))->assertForbidden();
    }

    public function test_global_chat_cannot_be_deleted(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get(route('chat.index')); // ensures global chat
        $global = Chat::where('type', 'global')->first();

        $this->actingAs($admin)->delete(route('chat.destroy', $global))->assertForbidden();
        $this->assertDatabaseHas('chats', ['id' => $global->id]);
    }

    public function test_can_send_and_download_file_attachment(): void
    {
        Storage::fake('local');
        $u = $this->user();
        $this->actingAs($u)->get(route('chat.index'));
        $chat = Chat::where('type', 'global')->first();

        $this->actingAs($u)->post(route('chat.send', $chat), [
            'message' => '', 'file' => UploadedFile::fake()->create('отчёт.pdf', 120, 'application/pdf'),
        ])->assertRedirect();

        $msg = ChatMessage::first();
        $this->assertNotEmpty($msg->attachments);
        Storage::disk('local')->assertExists($msg->attachments[0]['path']);

        $this->actingAs($u)->get(route('chat.attachment', [$msg->id, 0]))->assertOk();
    }

    public function test_attachments_endpoint_lists_chat_files(): void
    {
        Storage::fake('local');
        $u = $this->user();
        $this->actingAs($u)->get(route('chat.index'));
        $chat = Chat::where('type', 'global')->first();

        $this->actingAs($u)->post(route('chat.send', $chat), ['file' => UploadedFile::fake()->create('смета.xlsx', 30)]);

        $this->actingAs($u)->getJson(route('chat.attachments', $chat))
            ->assertOk()->assertJsonCount(1, 'attachments')->assertJsonFragment(['name' => 'смета.xlsx']);
    }

    public function test_message_delete_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(); $admin->assignRole('admin');
        $author = User::factory()->create(); $author->assignRole('employee');
        $other = User::factory()->create(); $other->assignRole('employee');

        $this->actingAs($author)->get(route('chat.index'));
        $chat = Chat::where('type', 'global')->first();
        $this->actingAs($author)->post(route('chat.send', $chat), ['message' => 'Моё сообщение']);
        $msg = ChatMessage::first();

        // Another regular employee cannot delete someone else's message.
        $this->actingAs($other)->delete(route('chat.messages.destroy', $msg))->assertForbidden();
        // Admin can delete any message.
        $this->actingAs($admin)->delete(route('chat.messages.destroy', $msg))->assertOk();
        $this->assertDatabaseMissing('chat_messages', ['id' => $msg->id]);
    }

    public function test_director_cannot_delete_others_message_only_admin(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $author = User::factory()->create(); $author->assignRole('employee');
        $director = User::factory()->create(); $director->assignRole('director');
        $this->actingAs($author)->get(route('chat.index'));
        $chat = Chat::where('type', 'global')->first();
        $this->actingAs($author)->post(route('chat.send', $chat), ['message' => 'моё']);
        $msg = \App\Models\ChatMessage::first();

        // Директор больше НЕ может удалять чужие (только админ).
        $this->actingAs($director)->delete(route('chat.messages.destroy', $msg))->assertForbidden();
    }

    public function test_reply_and_edit_own_message(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $a = User::factory()->create(); $a->assignRole('employee');
        $b = User::factory()->create(); $b->assignRole('employee');
        $this->actingAs($a)->get(route('chat.index'));
        $chat = Chat::where('type', 'global')->first();

        $this->actingAs($a)->post(route('chat.send', $chat), ['message' => 'Первое']);
        $first = \App\Models\ChatMessage::first();

        // Ответ-цитата на первое сообщение.
        $this->actingAs($b)->post(route('chat.send', $chat), ['message' => 'Отвечаю', 'reply_to_id' => $first->id])->assertRedirect();
        $reply = \App\Models\ChatMessage::where('message', 'Отвечаю')->first();
        $this->assertEquals($first->id, $reply->reply_to_id);

        // Автор редактирует своё → метка edited_at.
        $this->actingAs($b)->patch(route('chat.messages.update', $reply), ['message' => 'Исправлено'])->assertOk();
        $this->assertEquals('Исправлено', $reply->fresh()->message);
        $this->assertNotNull($reply->fresh()->edited_at);

        // Чужое сообщение редактировать нельзя.
        $this->actingAs($a)->patch(route('chat.messages.update', $reply), ['message' => 'взлом'])->assertForbidden();
    }

    public function test_reaction_toggle(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $u = User::factory()->create(); $u->assignRole('employee');
        $this->actingAs($u)->get(route('chat.index'));
        $chat = Chat::where('type', 'global')->first();
        $this->actingAs($u)->post(route('chat.send', $chat), ['message' => 'привет']);
        $msg = \App\Models\ChatMessage::first();

        // Поставить реакцию.
        $this->actingAs($u)->post(route('chat.messages.react', $msg), ['emoji' => '👍'])->assertOk();
        $this->assertDatabaseHas('chat_message_reactions', ['chat_message_id' => $msg->id, 'user_id' => $u->id, 'emoji' => '👍']);
        // Повторно — снимает (тумблер).
        $this->actingAs($u)->post(route('chat.messages.react', $msg), ['emoji' => '👍'])->assertOk();
        $this->assertDatabaseMissing('chat_message_reactions', ['chat_message_id' => $msg->id, 'user_id' => $u->id, 'emoji' => '👍']);
    }

    public function test_mention_notifies_user_and_read_marks_chat(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $a = User::factory()->create(); $a->assignRole('admin');
        $b = User::factory()->create(); $b->assignRole('employee');
        $this->actingAs($a)->post(route('chat.store'), ['type' => 'group', 'name' => 'Г', 'participants' => [$b->id]]);
        $chat = Chat::where('type', 'group')->first();

        // Упоминание @B → уведомление b.
        $this->actingAs($a)->post(route('chat.send', $chat), ['message' => 'привет @'.$b->name, 'mention_ids' => [$b->id]])->assertRedirect();
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $b->id, 'type' => \App\Notifications\ChatMention::class]);

        // b отмечает чат прочитанным → счётчик непрочитанных 0.
        $this->actingAs($b)->post(route('chat.read', $chat))->assertOk();
        $lastId = \App\Models\ChatMessage::max('id');
        $this->assertDatabaseHas('chat_reads', ['chat_id' => $chat->id, 'user_id' => $b->id, 'last_read_message_id' => $lastId]);
    }

    public function test_pin_message_admin_only(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create(); $admin->assignRole('admin');
        $emp = User::factory()->create(); $emp->assignRole('employee');
        $this->actingAs($admin)->post(route('chat.store'), ['type' => 'group', 'name' => 'Г', 'participants' => [$emp->id]]);
        $chat = Chat::where('type', 'group')->first();
        $this->actingAs($admin)->post(route('chat.send', $chat), ['message' => 'важное']);
        $msg = \App\Models\ChatMessage::first();

        // Сотрудник закрепить не может.
        $this->actingAs($emp)->post(route('chat.messages.pin', $msg))->assertForbidden();
        // Админ закрепляет / открепляет (тумблер).
        $this->actingAs($admin)->post(route('chat.messages.pin', $msg))->assertOk();
        $this->assertEquals($msg->id, $chat->fresh()->pinned_message_id);
        $this->actingAs($admin)->post(route('chat.messages.pin', $msg))->assertOk();
        $this->assertNull($chat->fresh()->pinned_message_id);
    }
}
