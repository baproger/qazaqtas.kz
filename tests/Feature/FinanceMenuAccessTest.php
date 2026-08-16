<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Группа меню «Финансы» показывает пункты по правам, а сами страницы этими
 * же правами и закрыты. Меню живёт в JS, поэтому проверяем то, на чём оно
 * держится: доступ к маршрутам и права в пропсах страницы.
 */
class FinanceMenuAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->companies()->attach(Company::where('code', 'QT')->value('id'));

        return $user;
    }

    /** Цеховому группа открывается «Моими расходами» и «Зарплатой». */
    public function test_worker_sees_only_personal_finance_pages(): void
    {
        $worker = $this->staff('employee');

        $this->actingAs($worker)->get(route('myExpenses.index'))->assertOk();
        $this->actingAs($worker)->get(route('payroll.index'))->assertOk();
        // «Обзор» и «Расходы» — деньги фирмы, сотруднику они закрыты.
        $this->actingAs($worker)->get(route('finance.index'))->assertForbidden();
        $this->actingAs($worker)->get(route('expensesBoard.index'))->assertForbidden();

        $this->actingAs($worker)->get(route('myExpenses.index'))
            ->assertInertia(fn ($page) => $page
                ->where('auth.user.permissions', fn ($p) => $p->contains('expense.create')
                    && ! $p->contains('invoice.viewAny')));
    }

    /** Менеджер подаёт заявки, но рабочего места бухгалтера не видит. */
    public function test_manager_has_no_accountant_pages(): void
    {
        $manager = $this->staff('manager');

        $this->actingAs($manager)->get(route('myExpenses.index'))->assertOk();
        $this->actingAs($manager)->get(route('expensesBoard.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('finance.index'))->assertForbidden();
    }

    /** Разделы Финансов — отдельные страницы, и все закрыты от сотрудника. */
    public function test_finance_sections_are_separate_pages(): void
    {
        $accountant = $this->staff('financist');
        $worker = $this->staff('employee');

        foreach (['finance.invoices', 'finance.receipts', 'finance.debts'] as $name) {
            $this->actingAs($accountant)->get(route($name))->assertOk();
            $this->actingAs($worker)->get(route($name))->assertForbidden();
        }
    }

    /** Бухгалтеру доступна вся группа целиком. */
    public function test_accountant_sees_the_whole_group(): void
    {
        $accountant = $this->staff('financist');

        $this->actingAs($accountant)->get(route('finance.index'))->assertOk();
        $this->actingAs($accountant)->get(route('expensesBoard.index'))->assertOk();
        $this->actingAs($accountant)->get(route('myExpenses.index'))->assertOk();
        $this->actingAs($accountant)->get(route('payroll.index'))->assertOk();
    }
}
