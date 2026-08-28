<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Фильтр переживает уход со страницы.
 *
 * Раньше он жил только в адресной строке: отобрал сделки, открыл карточку,
 * вернулся через меню — а меню ведёт на голый `/deals`, и отбор пропал.
 * Кнопка «Назад» его возвращала, клик по меню нет, и правило понять было
 * нельзя.
 */
class StickyFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $company = Company::where('code', 'QT')->value('id');
        $this->admin->companies()->attach($company);

        $stage = DealStage::orderBy('order')->value('id');
        foreach ([['QT-1', 'Шымкент'], ['QT-2', 'Алматы'], ['QT-3', 'Алматы']] as [$number, $branch]) {
            Deal::create([
                'company_id' => $company, 'number' => $number, 'name' => 'X',
                'company_name' => 'Клиент '.$number, 'client_name' => 'Товар', 'budget' => 100000,
                'status' => 'active', 'deal_stage_id' => $stage, 'branch' => $branch,
                'responsible_user_id' => $this->admin->id,
            ]);
        }
    }

    private function numbers($response): array
    {
        $numbers = [];
        $response->assertInertia(function (Assert $page) use (&$numbers) {
            $deals = data_get($page->toArray(), 'props.deals.data') ?? data_get($page->toArray(), 'props.deals');
            $numbers = collect($deals)->pluck('number')->sort()->values()->all();
        });

        return $numbers;
    }

    public function test_the_filter_survives_leaving_the_page(): void
    {
        // Отобрал Алматы…
        $this->assertSame(['QT-2', 'QT-3'], $this->numbers(
            $this->actingAs($this->admin)->get(route('deals.index', ['view' => 'list', 'branch' => 'Алматы'])),
        ));

        // …ушёл в карточку и вернулся через меню — на голый адрес.
        $this->actingAs($this->admin)->get(route('deals.show', Deal::first()->id));

        $back = $this->actingAs($this->admin)->get(route('deals.index', ['view' => 'list']));
        $this->assertSame(['QT-2', 'QT-3'], $this->numbers($back), 'Фильтр должен вернуться сам.');
    }

    /**
     * Восстановленный фильтр ВИДЕН.
     *
     * Молчаливое восстановление опаснее потерянного фильтра: открыл «Сделки»,
     * увидел две штуки вместо трёх и решил, что данные пропали.
     */
    public function test_a_restored_filter_announces_itself(): void
    {
        $this->actingAs($this->admin)->get(route('deals.index', ['branch' => 'Алматы']));

        // Пришёл с параметрами — фильтр виден в адресе, плашка не нужна.
        $this->actingAs($this->admin)->get(route('deals.index', ['branch' => 'Алматы']))
            ->assertInertia(fn (Assert $p) => $p->missing('stickyFilter')->etc());

        // Пришёл без параметров — набор подставлен, и об этом сказано.
        $this->actingAs($this->admin)->get(route('deals.index'))
            ->assertInertia(fn (Assert $p) => $p->where('stickyFilter.page', 'deals')->etc());
    }

    /**
     * Сброс СИЛЬНЕЕ памяти.
     *
     * Иначе пустой набор параметров не отличить от «пришёл впервые», и фильтр
     * возвращался бы сразу после сброса — кнопка выглядела бы сломанной.
     */
    public function test_clearing_beats_the_memory(): void
    {
        $this->actingAs($this->admin)->get(route('deals.index', ['branch' => 'Алматы']));

        $this->assertSame(['QT-1', 'QT-2', 'QT-3'], $this->numbers(
            $this->actingAs($this->admin)->get(route('deals.index', ['view' => 'list', 'clear' => 1])),
        ));

        // И память действительно стёрта, а не пропущена один раз.
        $this->assertSame(['QT-1', 'QT-2', 'QT-3'], $this->numbers(
            $this->actingAs($this->admin)->get(route('deals.index', ['view' => 'list'])),
        ));
    }

    /**
     * Отбор не переезжает к другому человеку за тем же компьютером.
     *
     * Сессия живёт в браузере, а вход её данные не стирает: слот с id
     * человека — единственное, что отделяет его отбор от чужого.
     */
    public function test_the_filter_does_not_leak_to_another_person(): void
    {
        $this->actingAs($this->admin)->get(route('deals.index', ['branch' => 'Алматы']));

        $other = User::factory()->create();
        $other->assignRole('admin');
        $other->companies()->attach(Company::where('code', 'QT')->value('id'));

        $this->actingAs($other)->get(route('deals.index'))
            ->assertInertia(fn (Assert $p) => $p->missing('stickyFilter')->etc());
    }
}
