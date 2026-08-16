<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Мои расходы» — личная страница сотрудника: свои заявки и свои выплаты.
 */
class MyExpensesPageTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->category = ExpenseCategory::create(['name' => 'Канцтовары', 'is_active' => true]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->companies()->attach(Company::where('code', 'QT')->value('id'));

        return $user;
    }

    /** Заявка компании: расход без сделки, автор — сотрудник. */
    private function request(User $author, array $extra = []): Expense
    {
        return Expense::create(array_merge([
            'responsible_user_id' => $author->id,
            'category_id' => $this->category->id,
            'amount' => 5000,
            'date' => now()->toDateString(),
            'description' => 'бензин',
            'status' => 'pending',
            'type' => 'direct',
        ], $extra));
    }

    /**
     * Главное свойство страницы: она показывает деньги ОДНОГО человека.
     * Чужую заявку не подсмотреть — выборка идёт по id того, кто вошёл.
     */
    public function test_page_shows_only_my_own_records(): void
    {
        $worker = $this->staff('employee');
        $other = $this->staff('employee');

        $mine = $this->request($worker, ['description' => 'моя заявка']);
        $this->request($other, ['description' => 'чужая заявка']);

        $this->actingAs($worker)->get(route('myExpenses.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Finance/MyExpenses')
                ->has('pending', 1)
                ->where('pending.0.id', $mine->id)
                ->where('pending.0.description', 'моя заявка'));
    }

    /**
     * Заявка, поданная в прошлом месяце и до сих пор не оплаченная, обязана
     * остаться на виду: это деньги сотрудника, которые ему не вернули.
     */
    public function test_pending_is_never_cut_by_the_month_filter(): void
    {
        $worker = $this->staff('employee');
        $old = $this->request($worker, ['date' => now()->subMonths(3)->toDateString()]);

        $this->actingAs($worker)->get(route('myExpenses.index'))
            ->assertInertia(fn ($page) => $page->has('pending', 1)->where('pending.0.id', $old->id));
    }

    /** Оплаченные и выдачи месяц фильтрует — иначе список рос бы без края. */
    public function test_paid_requests_are_filtered_by_the_month(): void
    {
        $worker = $this->staff('employee');
        $accountant = $this->staff('financist');

        $thisMonth = $this->request($worker, [
            'status' => 'confirmed', 'payment_method' => 'cash',
            'confirmed_by' => $accountant->id, 'confirmed_at' => now(),
        ]);
        $old = $this->request($worker, [
            'date' => now()->subMonths(2)->toDateString(),
            'status' => 'confirmed', 'payment_method' => 'bank',
            'confirmed_by' => $accountant->id, 'confirmed_at' => now(),
        ]);

        $this->actingAs($worker)->get(route('myExpenses.index'))
            ->assertInertia(fn ($page) => $page->has('paid', 1)->where('paid.0.id', $thisMonth->id));

        // Прошлый месяц открывается параметром — запись находится там.
        $this->actingAs($worker)->get(route('myExpenses.index', ['month' => now()->subMonths(2)->format('Y-m')]))
            ->assertInertia(fn ($page) => $page->has('paid', 1)->where('paid.0.id', $old->id));
    }

    /** Плитки — сумма показанных строк, а не отдельный расчёт. */
    public function test_tiles_match_the_rows(): void
    {
        $worker = $this->staff('employee');
        $accountant = $this->staff('financist');

        $this->request($worker, ['amount' => 1000]);
        $this->request($worker, ['amount' => 2500]);
        $this->request($worker, [
            'amount' => 400, 'status' => 'confirmed', 'payment_method' => 'cash',
            'confirmed_by' => $accountant->id, 'confirmed_at' => now(),
        ]);
        Expense::create([
            'responsible_user_id' => $accountant->id,
            'employee_id' => $worker->id,
            'employee_payout' => 'advance',
            'category_id' => $this->category->id,
            'amount' => 30000,
            'date' => now()->toDateString(),
            'status' => 'confirmed',
            'payment_method' => 'cash',
        ]);

        $this->actingAs($worker)->get(route('myExpenses.index'))
            ->assertInertia(fn ($page) => $page
                ->where('totals.pending', 3500)
                ->where('totals.paid', 400)
                ->where('totals.payouts', 30000)
                ->has('payouts', 1)
                ->where('payouts.0.payout', 'advance'));
    }

    /** Выдача другому сотруднику в «Мне выдано» не попадает. */
    public function test_payout_to_another_employee_is_not_visible(): void
    {
        $worker = $this->staff('employee');
        $other = $this->staff('employee');
        $accountant = $this->staff('financist');

        Expense::create([
            'responsible_user_id' => $accountant->id,
            'employee_id' => $other->id,
            'employee_payout' => 'debt',
            'category_id' => $this->category->id,
            'amount' => 50000,
            'date' => now()->toDateString(),
            'status' => 'confirmed',
            'payment_method' => 'cash',
        ]);

        $this->actingAs($worker)->get(route('myExpenses.index'))
            ->assertInertia(fn ($page) => $page->has('payouts', 0)->where('totals.payouts', 0));
    }

    /** Заявка из модалки: ждёт бухгалтера и сразу видна автору на странице. */
    public function test_request_from_the_modal_is_created_pending(): void
    {
        $worker = $this->staff('employee');

        $this->actingAs($worker)->post(route('expenses.store'), [
            'category_id' => $this->category->id,
            'amount' => 7000,
            'date' => now()->toDateString(),
            'description' => 'канцтовары',
        ])->assertSessionHasNoErrors();

        $this->actingAs($worker)->get(route('myExpenses.index'))
            ->assertInertia(fn ($page) => $page
                ->has('pending', 1)
                ->where('pending.0.status', 'pending')
                ->where('pending.0.payment_method', null)
                ->where('totals.pending', 7000));
    }

    /** Без права подавать заявки страница закрыта. */
    public function test_user_without_the_right_is_refused(): void
    {
        $nobody = User::factory()->create();

        $this->actingAs($nobody)->get(route('myExpenses.index'))->assertForbidden();
    }
}
