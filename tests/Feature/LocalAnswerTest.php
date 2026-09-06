<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealItem;
use App\Models\DealStage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\LocalAnswerService;
use App\Services\QuestionScope;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Бесплатный режим помощника: ответы собираются из базы, без ИИ и без ключа.
 *
 * Проверяем и разбор вопроса (период, человек, город, топ-N), и сами
 * выборки: они написаны сырым SQL и молча ломаются при переименовании
 * колонок, поэтому каждая тема должна быть под тестом.
 */
class LocalAnswerTest extends TestCase
{
    use RefreshDatabase;

    private User $erman;

    private User $aigul;

    private DealStage $stage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(StageSeeder::class);

        $this->erman = User::factory()->create(['name' => 'Ерман Сериков']);
        $this->aigul = User::factory()->create(['name' => 'Айгуль Нурлановна']);
        $this->stage = DealStage::where('is_won', false)->orderBy('order')->firstOrFail();
    }

    private function deal(array $attrs = []): Deal
    {
        return Deal::create(array_merge([
            'number' => 'QT-'.rand(10000, 99999),
            'name' => 'Сделка',
            'status' => 'active',
            'deal_stage_id' => $this->stage->id,
            'budget' => 100000,
        ], $attrs));
    }

    /** Оплата в системе всегда привязана к счёту — создаём пару. */
    private function payment(float $amount, \Illuminate\Support\Carbon $date): void
    {
        $invoice = Invoice::create([
            'invoiceable_type' => 'deal', 'invoiceable_id' => $this->deal()->id,
            'number' => 'INV-'.uniqid(), 'amount' => $amount, 'status' => 'sent',
            'issue_date' => $date->toDateString(), 'due_date' => $date->toDateString(),
        ]);

        Payment::create([
            'invoice_id' => $invoice->id, 'amount' => $amount,
            'payment_date' => $date->toDateString(), 'payment_method' => 'cash',
        ]);
    }

    private function ask(string $question): string
    {
        return (string) app(LocalAnswerService::class)->answer($question);
    }

    // ------------------------------------------------------------------
    // Разбор вопроса
    // ------------------------------------------------------------------

    public function test_scope_reads_period_person_city_and_top(): void
    {
        $s = QuestionScope::parse('покажи топ-5 клиентов Ермана за месяц по Шымкенту');

        $this->assertSame('за месяц', $s->periodLabel);
        $this->assertSame($this->erman->id, $s->user?->id);
        $this->assertSame('Шымкент', $s->city);
        $this->assertSame(5, $s->limit);
    }

    public function test_scope_tells_this_month_from_last_month(): void
    {
        $this->assertSame('за прошлый месяц', QuestionScope::parse('деньги за прошлый месяц')->periodLabel);
        $this->assertSame('за месяц', QuestionScope::parse('деньги за месяц')->periodLabel);
        $this->assertSame('за сегодня', QuestionScope::parse('что сегодня')->periodLabel);
        $this->assertNull(QuestionScope::parse('покажи склад')->periodLabel);
    }

    public function test_scope_finds_a_person_in_any_case_form(): void
    {
        // «у Ермана» — падеж меняет окончание, корень остаётся.
        $this->assertSame($this->erman->id, QuestionScope::parse('что у Ермана?')->user?->id);
        $this->assertSame($this->aigul->id, QuestionScope::parse('сделки Айгуль')->user?->id);
        $this->assertNull(QuestionScope::parse('покажи все сделки')->user);
    }

    // ------------------------------------------------------------------
    // Темы
    // ------------------------------------------------------------------

    public function test_top_clients_are_ranked_by_money(): void
    {
        $this->deal(['client_name' => 'ТОО Алатау', 'budget' => 900000]);
        $this->deal(['client_name' => 'ТОО Алатау', 'budget' => 100000]);
        $this->deal(['client_name' => 'ИП Мелкий', 'budget' => 50000]);

        $answer = $this->ask('топ клиентов');

        $this->assertStringContainsString('ТОО Алатау', $answer);
        $this->assertStringContainsString('1 000 000 ₸', $answer);   // две сделки сложились
        $this->assertLessThan(                                        // крупный клиент выше мелкого
            mb_strpos($answer, 'ИП Мелкий'),
            mb_strpos($answer, 'ТОО Алатау'),
        );
    }

    public function test_manager_rating_sums_their_deals(): void
    {
        $this->deal(['responsible_user_id' => $this->erman->id, 'budget' => 700000]);
        $this->deal(['responsible_user_id' => $this->aigul->id, 'budget' => 300000]);

        $answer = $this->ask('кто больше продал');

        $this->assertStringContainsString('Ерман Сериков', $answer);
        $this->assertStringContainsString('700 000 ₸', $answer);
        $this->assertLessThan(mb_strpos($answer, 'Айгуль'), mb_strpos($answer, 'Ерман'));
    }

    public function test_products_are_ranked_by_amount(): void
    {
        $deal = $this->deal();
        DealItem::create(['deal_id' => $deal->id, 'name' => 'Бордюр дорожный', 'unit' => 'шт',
            'quantity' => 200, 'price' => 3000, 'amount' => 600000]);
        DealItem::create(['deal_id' => $deal->id, 'name' => 'Вазон «Астана»', 'unit' => 'шт',
            'quantity' => 5, 'price' => 20000, 'amount' => 100000]);

        $answer = $this->ask('какие товары продаются');

        $this->assertStringContainsString('Бордюр дорожный', $answer);
        $this->assertStringContainsString('600 000 ₸', $answer);
        $this->assertLessThan(mb_strpos($answer, 'Вазон'), mb_strpos($answer, 'Бордюр'));
    }

    public function test_question_about_a_person_narrows_the_answer(): void
    {
        $this->deal(['responsible_user_id' => $this->erman->id, 'budget' => 700000]);
        $this->deal(['responsible_user_id' => $this->aigul->id, 'budget' => 300000]);

        $answer = $this->ask('какие сделки у Ермана');

        // В сумме только его сделка, и рамка вопроса подписана.
        $this->assertStringContainsString('700 000 ₸', $answer);
        $this->assertStringNotContainsString('1 000 000 ₸', $answer);
        $this->assertStringContainsString('Ерман Сериков', $answer);
    }

    public function test_period_narrows_the_answer(): void
    {
        $this->deal(['budget' => 400000]);                                   // сегодня
        $old = $this->deal(['budget' => 900000]);                            // полгода назад
        $old->forceFill(['created_at' => now()->subMonths(6)])->saveQuietly();

        $this->assertStringContainsString('400 000 ₸', $this->ask('сделки за неделю'));
        $this->assertStringNotContainsString('900 000', $this->ask('сделки за неделю'));
        // Без периода видны обе.
        $this->assertStringContainsString('1 300 000 ₸', $this->ask('покажи сделки'));
    }

    public function test_city_narrows_the_answer(): void
    {
        $this->deal(['budget' => 400000, 'branch' => 'Шымкент']);
        $this->deal(['budget' => 900000, 'address' => 'г. Алматы, ул. Абая 1']);

        $this->assertStringContainsString('400 000 ₸', $this->ask('сделки по Шымкенту'));
        $this->assertStringContainsString('900 000 ₸', $this->ask('сделки по Алматы'));
    }

    public function test_money_compares_with_the_previous_stretch(): void
    {
        $this->payment(500000, now());
        $this->payment(200000, now()->subMonthNoOverflow()->startOfMonth()->addDay());

        $answer = $this->ask('сколько денег за месяц');

        $this->assertStringContainsString('500 000 ₸', $answer);
        $this->assertStringContainsString('Предыдущий такой же отрезок', $answer);
    }

    public function test_unknown_question_stays_unanswered(): void
    {
        // Не выдумываем ответ: пусть вызывающий покажет список возможностей.
        $this->assertNull(app(LocalAnswerService::class)->answer('напиши поздравление с Наурызом'));
    }

    public function test_every_topic_survives_an_empty_database(): void
    {
        foreach (['просроченные сделки', 'остатки на складе', 'что в цехе', 'какие задачи',
            'сколько денег', 'воронка сделок', 'топ клиентов', 'кто больше продал',
            'какие товары продаются', 'дай сводку'] as $q) {
            $this->assertIsString(app(LocalAnswerService::class)->answer($q), "Упало на: {$q}");
        }
    }
}
