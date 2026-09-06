<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use App\Services\AiAssistantService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ИИ-помощник: доступ только у первых лиц, диалоги приватные, лимиты держат.
 *
 * Настоящий API Anthropic в тестах не дёргаем — сервис подменяется моком:
 * тесты должны быть быстрыми и бесплатными.
 */
class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    /** Подменяет ответ помощника — сеть не нужна. */
    private function fakeAnswer(string $text = 'Ответ помощника'): void
    {
        $this->mock(AiAssistantService::class, fn ($m) => $m->shouldReceive('answer')->andReturn([
            'content' => $text, 'input_tokens' => 120, 'output_tokens' => 40, 'ok' => true,
        ]));
    }

    public function test_leadership_opens_the_assistant(): void
    {
        foreach (['admin', 'director'] as $role) {
            $this->actingAs($this->user($role))->get(route('ai.index'))->assertOk();
        }
    }

    public function test_other_roles_are_denied(): void
    {
        foreach (['manager', 'financist', 'foreman'] as $role) {
            $this->actingAs($this->user($role))->get(route('ai.index'))->assertForbidden();
        }
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('ai.index'))->assertRedirect(route('login'));
    }

    public function test_question_creates_conversation_with_two_messages(): void
    {
        $director = $this->user('director');
        $this->fakeAnswer('По складу всё в порядке');

        $this->actingAs($director)
            ->post(route('ai.send'), ['message' => 'Что по складу?'])
            ->assertRedirect();

        $c = AiConversation::firstOrFail();
        $this->assertSame($director->id, $c->user_id);
        $this->assertSame('Что по складу?', $c->title);
        $this->assertSame(['user', 'assistant'], $c->messages()->orderBy('id')->pluck('role')->all());
        $this->assertSame('По складу всё в порядке', $c->messages()->where('role', 'assistant')->value('content'));
        // Расход токенов фиксируется на ответе — по нему считаются траты.
        $this->assertSame(40, $c->messages()->where('role', 'assistant')->value('output_tokens'));
    }

    public function test_foreign_conversation_is_closed(): void
    {
        $mine = $this->user('director');
        $other = User::factory()->create();
        $other->assignRole('admin');
        $foreign = AiConversation::create(['user_id' => $other->id, 'title' => 'Чужой диалог']);

        // Даже админу чужая переписка не показывается.
        $this->actingAs($mine)->get(route('ai.show', $foreign))->assertForbidden();
        $this->actingAs($mine)->delete(route('ai.destroy', $foreign))->assertForbidden();
        $this->actingAs($mine)->post(route('ai.send'), [
            'message' => 'Подсмотрю чужое', 'conversation_id' => $foreign->id,
        ])->assertForbidden();
    }

    public function test_message_is_validated(): void
    {
        $director = $this->user('director');
        $this->fakeAnswer();

        $this->actingAs($director)->post(route('ai.send'), ['message' => ''])
            ->assertSessionHasErrors('message');
        $this->actingAs($director)->post(route('ai.send'), ['message' => str_repeat('а', 4001)])
            ->assertSessionHasErrors('message');
        $this->assertSame(0, AiConversation::count());
    }

    public function test_daily_limit_stops_further_questions(): void
    {
        config(['services.anthropic.daily_limit' => 2]);
        $director = $this->user('director');
        $this->fakeAnswer();

        $this->actingAs($director)->post(route('ai.send'), ['message' => 'Первый вопрос'])->assertRedirect();
        $this->actingAs($director)->post(route('ai.send'), ['message' => 'Второй вопрос'])->assertRedirect();
        $this->actingAs($director)->post(route('ai.send'), ['message' => 'Третий вопрос'])
            ->assertSessionHasErrors('message');

        $this->assertSame(2, AiConversation::count());
    }

    public function test_without_api_key_data_questions_are_answered_from_database(): void
    {
        // Ключа нет — но вопрос типовой, и помощник обязан ответить цифрами.
        config(['services.anthropic.key' => null]);
        $this->seed(StageSeeder::class);
        $director = $this->user('director');

        $stage = DealStage::where('is_won', false)->orderBy('order')->firstOrFail();
        Deal::create([
            'number' => 'QT-777', 'name' => 'Просроченная', 'client_name' => 'ТОО Тест',
            'budget' => 500000, 'status' => 'active', 'deal_stage_id' => $stage->id,
            'deadline' => now()->subDays(5)->toDateString(),
        ]);

        $conversation = AiConversation::create(['user_id' => $director->id, 'title' => 'Тест']);
        $answer = app(AiAssistantService::class)->answer($conversation, 'Что по просроченным сделкам?');

        $this->assertTrue($answer['ok']);
        $this->assertStringContainsString('QT-777', $answer['content']);
        $this->assertStringContainsString('ТОО Тест', $answer['content']);
        // Ответ из базы токенов не тратит.
        $this->assertNull($answer['output_tokens']);
    }

    public function test_without_api_key_free_question_lists_what_is_available(): void
    {
        config(['services.anthropic.key' => null]);
        $director = $this->user('director');
        $conversation = AiConversation::create(['user_id' => $director->id, 'title' => 'Тест']);

        $answer = app(AiAssistantService::class)->answer($conversation, 'Напиши письмо поставщику про отсрочку');

        $this->assertFalse($answer['ok']);
        $this->assertStringContainsString('Просроченные сделки', $answer['content']);
        $this->assertStringContainsString('ANTHROPIC_API_KEY', $answer['content']);
    }

    public function test_local_answers_cover_every_topic(): void
    {
        // Каждая тема должна отрабатывать без падений даже на пустой базе:
        // сырые SQL-запросы легко ломаются при переименовании колонок.
        $local = app(\App\Services\LocalAnswerService::class);

        foreach (['просроченные сделки', 'остатки на складе', 'что в цехе',
            'какие задачи открыты', 'сколько денег поступило', 'воронка сделок',
            'дай сводку'] as $q) {
            $this->assertIsString($local->answer($q), "Тема не распознана: {$q}");
        }

        $this->assertNull($local->answer('напиши поздравление с Наурызом'));
    }

    public function test_context_summary_is_built_without_errors(): void
    {
        // Сводка собирается из БД сырыми запросами — проверяем, что имена
        // таблиц и колонок живые, иначе помощник молча ответит без цифр.
        config(['services.anthropic.key' => null]);
        $director = $this->user('director');

        $svc = app(AiAssistantService::class);
        $method = new \ReflectionMethod($svc, 'buildContext');
        $context = $method->invoke($svc, null);

        $this->assertIsString($context);
        $this->assertStringNotContainsString('временно недоступна', $context);
    }
}
