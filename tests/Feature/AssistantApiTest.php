<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportController;
use App\Models\AiChatLog;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use App\Services\Ai\AssistantTools;
use App\Support\AiKey;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Агент помощника: инструменты, протокол Gemini, доступ и журнал.
 *
 * Настоящий API не дёргаем — HTTP подменён: тесты обязаны быть быстрыми,
 * бесплатными и не зависеть от чужих квот.
 */
class AssistantApiTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->director = User::factory()->create(['name' => 'Ерман Сериков']);
        $this->director->assignRole('director');

        AiKey::save('AIzaTestKey123');   // ключ Google → ветка Gemini
    }

    private function deal(array $attrs = []): Deal
    {
        return Deal::create(array_merge([
            'number' => 'QT-'.rand(10000, 99999), 'name' => 'Сделка', 'status' => 'active',
            'deal_stage_id' => DealStage::where('is_won', false)->orderBy('order')->value('id'),
            'budget' => 100000,
        ], $attrs));
    }

    /** Ответ Gemini: сначала вызов инструмента, потом текст. */
    private function fakeGemini(string $tool, string $finalText): void
    {
        $call = ['candidates' => [['content' => ['parts' => [
            ['functionCall' => ['name' => $tool, 'args' => (object) []]],
        ]]]]];
        $text = ['candidates' => [['content' => ['parts' => [['text' => $finalText]]]]]];

        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($call, 200)
            ->push($text, 200)]);
    }

    // ------------------------------------------------------------------
    // Доступ
    // ------------------------------------------------------------------

    public function test_role_without_access_is_denied(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->actingAs($manager)->postJson('/api/assistant/ask', ['question' => 'Прибыль за месяц?'])
            ->assertForbidden();
        $this->actingAs($manager)->getJson('/api/assistant/history')->assertForbidden();
    }

    public function test_guest_is_rejected(): void
    {
        $this->postJson('/api/assistant/ask', ['question' => 'Прибыль?'])->assertUnauthorized();
    }

    // ------------------------------------------------------------------
    // Полный путь вопроса
    // ------------------------------------------------------------------

    public function test_agent_calls_a_tool_and_answers(): void
    {
        $this->deal(['budget' => 500000, 'client_name' => 'ТОО Тест']);
        $this->fakeGemini('deals_list', 'Сейчас **1** сделка на 500 000 ₸.');

        $this->actingAs($this->director)
            ->postJson('/api/assistant/ask', ['question' => 'Сколько сделок?'])
            ->assertOk()
            ->assertJson(['answer' => 'Сейчас **1** сделка на 500 000 ₸.', 'used_tools' => ['deals_list']]);

        // Пара «вопрос — ответ» легла в журнал и видна в истории.
        $this->assertSame(1, AiChatLog::count());
        $this->actingAs($this->director)->getJson('/api/assistant/history')
            ->assertOk()
            ->assertJsonPath('items.0.question', 'Сколько сделок?')
            ->assertJsonPath('items.0.used_tools.0', 'deals_list')
            ->assertJsonPath('items.0.user', 'Ерман Сериков');
    }

    public function test_gemini_request_has_the_required_shape(): void
    {
        $this->fakeGemini('tasks_overview', 'Готово.');

        $this->actingAs($this->director)->postJson('/api/assistant/ask', ['question' => 'Задачи?'])->assertOk();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->hasHeader('x-goog-api-key', 'AIzaTestKey123')
                && isset($body['system_instruction']['parts'][0]['text'])
                && isset($body['tools'][0]['function_declarations'])
                && $body['generationConfig']['maxOutputTokens'] === 1500
                && $body['generationConfig']['thinkingConfig']['thinkingLevel'] === 'low';
        });
    }

    public function test_tool_result_is_sent_back_as_an_object(): void
    {
        // Проверяем СЫРОЙ JSON: именно в нём жила ошибка — PHP отдавал
        // пустые структуры списком «[]», а протокол ждёт объект «{}».
        $this->fakeGemini('tasks_overview', 'Ответ.');
        $this->actingAs($this->director)->postJson('/api/assistant/ask', ['question' => 'Задачи?'])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->body(), '"functionResponse"')
            ? str_contains($request->body(), '"response":{')
            : true);
    }

    public function test_empty_tool_arguments_are_returned_as_an_object(): void
    {
        // Gemini отвечал «Proto field is not repeating, cannot start list»:
        // вызов без аргументов приходил как {}, PHP декодировал его в пустой
        // массив и отправлял обратно как [].
        $this->fakeGemini('overdue_deals', 'Просрочек нет.');

        $this->actingAs($this->director)
            ->postJson('/api/assistant/ask', ['question' => 'Что просрочено?'])
            ->assertOk();

        // Ни один запрос не должен содержать список вместо объекта…
        Http::assertNotSent(fn ($request) => str_contains($request->body(), '"args":[]'));
        // …и ход модели с пустыми аргументами должен уйти именно как {}.
        Http::assertSent(fn ($request) => str_contains($request->body(), '"args":{}'));
    }

    public function test_model_falls_back_when_quota_is_exhausted(): void
    {
        // 429 у первой модели — берём следующую, а не падаем.
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'quota']], 429)
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'Ответ со второй модели']]]]]], 200)]);

        $this->actingAs($this->director)
            ->postJson('/api/assistant/ask', ['question' => 'Что по складу?'])
            ->assertOk()
            ->assertJsonPath('answer', 'Ответ со второй модели');
    }

    public function test_rate_limit_stops_the_ninth_question(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
            ['candidates' => [['content' => ['parts' => [['text' => 'ок']]]]]], 200)]);

        for ($i = 0; $i < 8; $i++) {
            $this->actingAs($this->director)->postJson('/api/assistant/ask', ['question' => "Вопрос {$i}"])->assertOk();
        }

        $this->actingAs($this->director)->postJson('/api/assistant/ask', ['question' => 'Девятый'])
            ->assertStatus(429)
            ->assertJsonStructure(['error']);
    }

    public function test_free_mode_is_not_rate_limited(): void
    {
        // Без ключа ответы читаются из своей базы — ограничивать их незачем.
        // Раньше пара нажатий на примеры съедала лимит и человек упирался в 429.
        AiKey::forget();
        config(['services.anthropic.key' => null]);

        for ($i = 0; $i < 12; $i++) {
            $this->actingAs($this->director)
                ->postJson('/api/assistant/ask', ['question' => 'покажи остатки по складу'])
                ->assertOk();
        }
    }

    public function test_bad_key_is_explained_in_human_words(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'bad key']], 403)]);

        $this->actingAs($this->director)
            ->postJson('/api/assistant/ask', ['question' => 'Напиши письмо поставщику'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Ключ ИИ не принят сервисом. Проверьте его в Настройках.');
    }

    // ------------------------------------------------------------------
    // Инструменты
    // ------------------------------------------------------------------

    public function test_sales_report_matches_the_report_page_to_the_tenge(): void
    {
        $this->deal(['budget' => 1200000, 'client_name' => 'ТОО Финанс']);
        $this->deal(['budget' => 800000, 'client_name' => 'ИП Малый']);

        $tools = new AssistantTools($this->director);
        $answer = $tools->run('sales_report', []);

        // Та же цифра, что покажет страница «Сводный отчёт» за тот же период.
        $request = Request::create('/reports/deals', 'GET', [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ]);
        $request->setUserResolver(fn () => $this->director);
        $page = app(ReportController::class)->assistantTotals($request);

        $this->assertSame((float) $page['budget'], (float) $answer['contracts_sum']);
        $this->assertSame((float) $page['company'], (float) $answer['company_profit']);
        $this->assertSame($page['count'], $answer['deals_count']);
        $this->assertSame(2, $answer['deals_count']);
    }

    public function test_employee_summary_counts_on_the_server_and_returns_links(): void
    {
        $this->deal(['responsible_user_id' => $this->director->id, 'budget' => 300000]);
        $this->deal(['responsible_user_id' => $this->director->id, 'budget' => 200000]);
        $this->deal(['budget' => 999000]);   // чужая — в счёт не идёт

        $answer = (new AssistantTools($this->director))->run('employee_summary', ['name' => 'ерман']);

        $this->assertSame('Ерман Сериков', $answer['employee']);
        $this->assertSame(2, $answer['deals_count_total']);    // считает сервер
        $this->assertSame(500000.0, $answer['deals_sum_total']);
        // У каждой сущности есть ссылка — модель обязана дать её пользователю.
        $this->assertMatchesRegularExpression('#^/deals/\d+$#', $answer['recent_deals'][0]['link']);
        $this->assertMatchesRegularExpression('#^/users/\d+$#', $answer['link']);
    }

    public function test_employee_summary_names_both_period_and_total(): void
    {
        // Модель сама подставляла текущий месяц и отвечала «1 сделка» там,
        // где у человека их две. Теперь инструмент всегда отдаёт обе цифры.
        $old = $this->deal(['responsible_user_id' => $this->director->id, 'budget' => 1000000]);
        $old->forceFill(['created_at' => now()->subMonths(2)])->saveQuietly();
        $this->deal(['responsible_user_id' => $this->director->id, 'budget' => 400000]);

        $answer = (new AssistantTools($this->director))->run('employee_summary', [
            'name' => 'ерман',
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
        ]);

        $this->assertSame(2, $answer['deals_count_total']);       // всего у человека
        $this->assertSame(1, $answer['deals_count_in_period']);   // из них за месяц
        $this->assertStringContainsString('обе', $answer['hint']);
    }

    public function test_overdue_days_are_positive(): void
    {
        // Разница дат в Carbon знаковая: без abs() выходило «просрочка -6 дн.».
        $this->deal(['deadline' => now()->subDays(6)->toDateString()]);

        $answer = (new AssistantTools($this->director))->run('overdue_deals', []);

        $this->assertSame(1, $answer['count']);
        $this->assertSame(6, $answer['items'][0]['days_overdue']);
    }

    public function test_deals_list_can_filter_by_responsible(): void
    {
        $this->deal(['responsible_user_id' => $this->director->id, 'budget' => 700000]);
        $this->deal(['budget' => 900000]);   // чужая

        $answer = (new AssistantTools($this->director))->run('deals_list', ['responsible' => 'ерман']);

        $this->assertSame(1, $answer['count']);
        $this->assertSame(700000.0, $answer['sum']);
    }

    public function test_unknown_employee_returns_error_with_available_names(): void
    {
        $answer = (new AssistantTools($this->director))->run('employee_summary', ['name' => 'Несуществующий']);

        $this->assertArrayHasKey('error', $answer);
        $this->assertContains('Ерман Сериков', $answer['available']);
    }

    public function test_tool_without_permission_returns_error_not_data(): void
    {
        $foreman = User::factory()->create();
        $foreman->assignRole('foreman');

        $answer = (new AssistantTools($foreman))->run('sales_report', []);

        $this->assertArrayHasKey('error', $answer);
        $this->assertArrayNotHasKey('company_profit', $answer);
    }

    public function test_all_tools_answer_without_errors(): void
    {
        $tools = new AssistantTools($this->director);

        foreach ($tools->schema() as $tool) {
            $args = in_array($tool['name'], ['employee_summary', 'client_summary'], true) ? ['name' => 'ерман'] : [];
            $result = $tools->run($tool['name'], $args);

            $this->assertIsArray($result, "Инструмент {$tool['name']} вернул не массив");
            $this->assertArrayNotHasKey('exception', $result);
        }
    }
}
