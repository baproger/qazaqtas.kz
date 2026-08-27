<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Бригадир на сделке.
 *
 * Бригаду на объект назначает директор. Назначенный бригадир ведёт сделку в
 * цехе: открывает карточку, видит, что внутри, и двигает по этапам — но
 * денег не видит нигде. Чужие сделки для него не существуют.
 */
class DealForemanTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private User $foreman;

    private int $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->company = Company::where('code', 'QT')->value('id');

        $this->director = User::factory()->create();
        $this->director->assignRole('director');
        $this->director->companies()->attach($this->company);

        $this->foreman = User::factory()->create(['name' => 'Асхат Бекболат']);
        $this->foreman->assignRole('foreman');
        $this->foreman->companies()->attach($this->company);
    }

    private function deal(array $extra = []): Deal
    {
        return Deal::create(array_merge([
            'company_id' => $this->company,
            'number' => 'QT-'.uniqid(),
            'name' => 'Двор ЖК',
            'company_name' => 'ТОО «Клиент»',
            'address' => 'г. Шымкент, ул. Промышленная 1',
            'budget' => 5000000,
            'status' => 'active',
            'deal_stage_id' => DealStage::query()->orderBy('order')->value('id'),
        ], $extra));
    }

    /** Бригадира на сделку ставит директор. */
    public function test_director_assigns_the_foreman(): void
    {
        $deal = $this->deal();

        $this->actingAs($this->director)
            ->patch(route('deals.foreman', $deal->id), ['foreman_id' => $this->foreman->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->foreman->id, $deal->fresh()->foreman_id);
    }

    /** Сам бригадир себя на сделку не назначит. */
    public function test_foreman_cannot_assign_himself(): void
    {
        $deal = $this->deal();

        $this->actingAs($this->foreman)
            ->patch(route('deals.foreman', $deal->id), ['foreman_id' => $this->foreman->id])
            ->assertForbidden();

        $this->assertNull($deal->fresh()->foreman_id);
    }

    /** Бригадиром можно поставить только человека с этой ролью. */
    public function test_only_a_foreman_can_be_assigned(): void
    {
        $deal = $this->deal();
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->actingAs($this->director)
            ->patch(route('deals.foreman', $deal->id), ['foreman_id' => $manager->id])
            ->assertStatus(422);

        $this->assertNull($deal->fresh()->foreman_id);
    }

    /** В списке бригадир видит только назначенные ему сделки. */
    public function test_foreman_sees_only_his_deals(): void
    {
        $mine = $this->deal(['foreman_id' => $this->foreman->id, 'company_name' => 'Моя']);
        $this->deal(['company_name' => 'Чужая']);

        $this->actingAs($this->foreman)->get(route('deals.index'))
            ->assertInertia(fn ($page) => $page
                ->where('deals', fn ($deals) => collect($deals)->pluck('id')->all() === [$mine->id]));
    }

    /** Чужую сделку бригадир не откроет и по прямой ссылке. */
    public function test_another_deal_is_closed_for_the_foreman(): void
    {
        $other = $this->deal();

        $this->actingAs($this->foreman)->get(route('deals.show', $other->id))->assertForbidden();
    }

    /** Свою сделку открывает и видит, что внутри. */
    public function test_foreman_opens_his_deal(): void
    {
        $deal = $this->deal(['foreman_id' => $this->foreman->id]);

        $this->actingAs($this->foreman)->get(route('deals.show', $deal->id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Deals/Show')
                ->where('deal.address', 'г. Шымкент, ул. Промышленная 1')
                ->where('deal.company_name', 'ТОО «Клиент»')
                ->etc());
    }

    /**
     * И не видит сумм: ни договора, ни расходов, ни раскладки прибыли.
     *
     * Проверяем именно ответ сервера, а не то, что скрыто в шаблоне: сумма,
     * доехавшая до браузера, — уже утечка.
     */
    public function test_foreman_sees_no_money(): void
    {
        $deal = $this->deal(['foreman_id' => $this->foreman->id]);
        Expense::create([
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'amount' => 900000, 'date' => now()->toDateString(), 'status' => 'confirmed',
        ]);

        $this->actingAs($this->foreman)->get(route('deals.show', $deal->id))
            ->assertInertia(fn ($page) => $page
                ->where('profit', null)
                ->where('finance', null)
                ->where('can.money', false)
                ->where('deal', fn ($deal) => ! $deal->has('budget')
                    && ! $deal->has('expenses')
                    && ! $deal->has('invoices'))
                ->etc());
    }

    /**
     * Товары сделки бригадир видит, а их цены — нет.
     *
     * Что и сколько лить — это его работа; по цене позиции считается вся
     * сумма сделки, поэтому цены сервер даже не выбирает из БД.
     */
    public function test_foreman_sees_items_without_prices(): void
    {
        $deal = $this->deal(['foreman_id' => $this->foreman->id]);
        $deal->items()->create([
            'name' => 'Плитка «Ромб» 190×330×60', 'unit' => 'м²',
            'quantity' => 210, 'price' => 8500, 'amount' => 1785000, 'sort' => 0,
        ]);

        $this->actingAs($this->foreman)->get(route('deals.show', $deal->id))
            ->assertInertia(fn ($page) => $page
                ->where('deal.items.0.name', 'Плитка «Ромб» 190×330×60')
                ->where('deal.items.0.quantity', '210.00')
                ->where('deal.items.0', fn ($item) => ! $item->has('price') && ! $item->has('amount'))
                ->etc());
    }

    /** В списке сумм тоже нет. */
    public function test_deal_list_hides_money_from_the_foreman(): void
    {
        $this->deal(['foreman_id' => $this->foreman->id]);

        $this->actingAs($this->foreman)->get(route('deals.index'))
            ->assertInertia(fn ($page) => $page
                ->where('deals', fn ($deals) => ! array_key_exists('budget', collect($deals)->first())));
    }

    /** Свою сделку бригадир двигает по этапам. */
    public function test_foreman_moves_his_deal_to_the_next_stage(): void
    {
        $deal = $this->deal(['foreman_id' => $this->foreman->id]);
        $funnel = DealStage::query()->orderBy('order')->get();
        $target = $funnel->firstWhere('stage_type', null) ?? $funnel->get(1);

        $this->actingAs($this->foreman)
            ->patch(route('deals.stage', $deal->id), ['deal_stage_id' => $target->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($target->id, $deal->fresh()->deal_stage_id);
    }

    /** Но саму сделку не правит: суммы и поля — не его дело. */
    public function test_foreman_cannot_edit_the_deal(): void
    {
        $deal = $this->deal(['foreman_id' => $this->foreman->id]);

        // Набор полей полный: иначе запрос отобьёт валидация, и проверка
        // говорила бы не о правах, а о форме.
        $this->actingAs($this->foreman)
            ->put(route('deals.update', $deal->id), [
                'client_name' => 'Клиент',
                'company_name' => 'Переписал',
                'address' => 'Другой адрес',
                'budget' => 1,
                'deal_stage_id' => $deal->deal_stage_id,
            ])
            ->assertForbidden();

        $this->assertSame('ТОО «Клиент»', $deal->fresh()->company_name);
    }
}
