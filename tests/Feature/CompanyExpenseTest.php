<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyExpenseTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);

        return $u;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    public function test_financist_creates_company_expense_with_category(): void
    {
        $fin = $this->user('financist');
        $cat = ExpenseCategory::firstOrCreate(['name' => 'Аренда'], ['is_active' => true]);

        $this->actingAs($fin)->post(route('expenses.store'), [
            'category_id' => $cat->id, 'amount' => 250000, 'date' => now()->toDateString(),
            'payment_method' => 'bank', 'description' => 'Аренда офиса за июль', 'status' => 'confirmed',
        ])->assertRedirect();

        $e = Expense::first();
        $this->assertNotNull($e);
        $this->assertEquals('confirmed', $e->status); // бухгалтер — подтверждается сразу
        $this->assertEquals($cat->id, $e->category_id);
        $this->assertNull($e->expenseable_id);
    }

    public function test_company_expense_requires_category(): void
    {
        $fin = $this->user('financist');

        $this->actingAs($fin)->post(route('expenses.store'), [
            'amount' => 1000, 'date' => now()->toDateString(),
        ])->assertSessionHasErrors('category_id');
    }

    public function test_manager_cannot_create_company_expense(): void
    {
        $mgr = $this->user('manager');
        $cat = ExpenseCategory::firstOrCreate(['name' => 'Бензин / ГСМ'], ['is_active' => true]);

        $this->actingAs($mgr)->post(route('expenses.store'), [
            'category_id' => $cat->id, 'amount' => 1000, 'date' => now()->toDateString(),
        ])->assertForbidden();
    }

    public function test_finance_page_shows_summary(): void
    {
        $admin = $this->user('admin');

        $this->actingAs($admin)->get(route('finance.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->has('summary.income')->has('summary.expensesTotal')->has('summary.net')
                ->has('summary.cash')->has('summary.bank')->has('categories'));
    }

    public function test_financist_manages_expense_categories(): void
    {
        $fin = User::factory()->create();
        $fin->assignRole('financist');

        $this->actingAs($fin)->post(route('expenseCategories.store'), ['name' => 'Тестовая категория АБВ'])->assertRedirect();
        $cat = \App\Models\ExpenseCategory::where('name', 'Тестовая категория АБВ')->firstOrFail();

        $this->actingAs($fin)->put(route('expenseCategories.update', $cat->id), ['name' => 'Тестовая категория ГДЕ'])->assertRedirect();
        $this->assertSame('Тестовая категория ГДЕ', $cat->fresh()->name);

        // Без расходов — удаляется; с расходами — скрывается (is_active=false).
        $this->actingAs($fin)->delete(route('expenseCategories.destroy', $cat->id))->assertRedirect();
        $this->assertNull(\App\Models\ExpenseCategory::find($cat->id));

        $used = \App\Models\ExpenseCategory::create(['name' => 'Тестовая категория ЖЗИ', 'is_active' => true]);
        \App\Models\Expense::create(['category_id' => $used->id, 'amount' => 100, 'date' => now()->toDateString(), 'status' => 'confirmed']);
        $this->actingAs($fin)->delete(route('expenseCategories.destroy', $used->id))->assertRedirect();
        $this->assertFalse($used->fresh()->is_active);

        // Менеджеру нельзя.
        $mgr = User::factory()->create();
        $mgr->assignRole('manager');
        $this->actingAs($mgr)->post(route('expenseCategories.store'), ['name' => 'X'])->assertForbidden();
    }
}
