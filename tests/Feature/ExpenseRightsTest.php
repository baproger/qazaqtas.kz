<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Material;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Права на расходы: кто удаляет, кто правит, кому виден чек.
 */
class ExpenseRightsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $accountant;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');

        $this->accountant = User::factory()->create();
        $this->accountant->assignRole('financist');

        $this->deal = Deal::create([
            'number' => 'QT-001',
            'name' => 'Сделка',
            'company_name' => 'Сделка',
            'budget' => 500000,
            'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->value('id'),
            'responsible_user_id' => $this->manager->id,
        ]);
    }

    private function dealExpense(array $attrs = []): Expense
    {
        return Expense::create(array_merge([
            'expenseable_type' => 'deal',
            'expenseable_id' => $this->deal->id,
            'amount' => 10000,
            'date' => now()->toDateString(),
            'status' => 'pending',
            'responsible_user_id' => $this->manager->id,
        ], $attrs));
    }

    /**
     * Менеджер не удаляет расходы — даже свой неподтверждённый. Удаление
     * двигает деньги и склад, поэтому оно у бухгалтера.
     */
    public function test_manager_cannot_delete_even_own_pending_expense(): void
    {
        $expense = $this->dealExpense();

        $this->actingAs($this->manager)
            ->delete(route('expenses.destroy', $expense->id))
            ->assertForbidden();

        $this->assertNotNull($expense->fresh());
    }

    public function test_accountant_deletes_any_expense(): void
    {
        $expense = $this->dealExpense();

        $this->actingAs($this->accountant)
            ->delete(route('expenses.destroy', $expense->id))
            ->assertSessionHasNoErrors();

        // Расходы удаляются мягко: fresh() скоупы игнорирует, поэтому
        // проверяем сам факт удаления, а не отсутствие строки.
        $this->assertSoftDeleted($expense);
    }

    /**
     * Правило держится ролью, а не только правом: даже если expense.delete
     * вернут менеджеру через админку, удалять он не начнёт.
     */
    public function test_delete_is_blocked_by_role_even_with_permission(): void
    {
        $this->manager->givePermissionTo('expense.delete');
        $expense = $this->dealExpense();

        $this->actingAs($this->manager)
            ->delete(route('expenses.destroy', $expense->id))
            ->assertForbidden();
    }

    public function test_author_edits_own_pending_expense(): void
    {
        $expense = $this->dealExpense();

        $this->actingAs($this->manager)->put(route('expenses.update', $expense->id), [
            'expenseable_type' => 'deal',
            'expenseable_id' => $this->deal->id,
            'amount' => 12000,
            'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $this->assertSame('12000.00', $expense->fresh()->amount);
    }

    /**
     * Подтверждённый расход заморожен: иначе запрет на удаление обходился бы
     * правкой суммы на 1 ₸.
     */
    public function test_confirmed_expense_is_frozen_for_the_author(): void
    {
        $expense = $this->dealExpense([
            'status' => 'confirmed',
            'payment_method' => 'cash',
            'confirmed_by' => $this->accountant->id,
            'confirmed_at' => now(),
        ]);

        $this->actingAs($this->manager)->put(route('expenses.update', $expense->id), [
            'expenseable_type' => 'deal',
            'expenseable_id' => $this->deal->id,
            'amount' => 1,
            'date' => now()->toDateString(),
        ])->assertForbidden();

        $this->assertSame('10000.00', $expense->fresh()->amount);
    }

    /**
     * Признак заморозки — confirmed_by, а НЕ status: списание материала
     * система проводит сама (confirmed без confirmed_by), и для правки оно
     * не заморожено.
     */
    public function test_system_confirmed_material_writeoff_stays_editable(): void
    {
        $material = Material::create(['name' => 'Цемент', 'unit' => 'кг', 'price' => 100]);
        $expense = $this->dealExpense([
            'status' => 'confirmed',
            'confirmed_by' => null,
            'material_id' => $material->id,
            'qty' => 10,
        ]);

        $this->actingAs($this->manager)->put(route('expenses.update', $expense->id), [
            'expenseable_type' => 'deal',
            'expenseable_id' => $this->deal->id,
            'amount' => 10000,
            'date' => now()->toDateString(),
            'description' => 'уточнил назначение',
        ])->assertSessionHasNoErrors();

        $this->assertSame('уточнил назначение', $expense->fresh()->description);
    }

    /** Сотрудник цеха видит СВОЙ чек: без этого «Мои расходы» бессмысленны. */
    public function test_employee_sees_own_company_expense_receipt(): void
    {
        Storage::fake('local');
        $worker = User::factory()->create();
        $worker->assignRole('employee');

        $expense = Expense::create([
            'category_id' => ExpenseCategory::findByCode(ExpenseCategory::EMPLOYEE)->id,
            'amount' => 2000,
            'date' => now()->toDateString(),
            'status' => 'pending',
            'responsible_user_id' => $worker->id,
            'file_path' => UploadedFile::fake()->image('чек.jpg')->store('receipts', 'local'),
        ]);

        $this->actingAs($worker)->get(route('expenses.receipt', $expense->id))->assertOk();
    }

    /** Чужую заявку компании посторонний не откроет — это чужие деньги. */
    public function test_stranger_cannot_open_someone_elses_receipt(): void
    {
        Storage::fake('local');
        $author = User::factory()->create();
        $author->assignRole('employee');
        $stranger = User::factory()->create();
        $stranger->assignRole('employee');

        $expense = Expense::create([
            'category_id' => ExpenseCategory::findByCode(ExpenseCategory::EMPLOYEE)->id,
            'amount' => 2000,
            'date' => now()->toDateString(),
            'status' => 'pending',
            'responsible_user_id' => $author->id,
            'file_path' => UploadedFile::fake()->image('чек.jpg')->store('receipts', 'local'),
        ]);

        $this->actingAs($stranger)->get(route('expenses.receipt', $expense->id))->assertForbidden();
        $this->actingAs($this->accountant)->get(route('expenses.receipt', $expense->id))->assertOk();
    }

    /** Тот, кому выдали деньги, видит чек своей выплаты. */
    public function test_payout_recipient_sees_the_receipt(): void
    {
        Storage::fake('local');
        $worker = User::factory()->create();
        $worker->assignRole('employee');

        $expense = Expense::create([
            'category_id' => ExpenseCategory::findByCode(ExpenseCategory::EMPLOYEE)->id,
            'amount' => 50000,
            'date' => now()->toDateString(),
            'status' => 'confirmed',
            'payment_method' => 'cash',
            'employee_id' => $worker->id,
            'employee_payout' => 'advance',
            'file_path' => UploadedFile::fake()->image('чек.jpg')->store('receipts', 'local'),
        ]);

        $this->actingAs($worker)->get(route('expenses.receipt', $expense->id))->assertOk();
    }

    /** Роли сотрудников получили право подавать заявку. */
    public function test_all_staff_roles_can_create_expenses(): void
    {
        foreach (['employee', 'lawyer', 'cook', 'designer', 'supplier'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->assertTrue($user->can('expense.create'), "Роль {$role} должна подавать заявку.");
            $this->assertFalse($user->can('expense.delete'), "Роль {$role} не должна удалять расходы.");
        }
    }

    public function test_manager_lost_delete_permission(): void
    {
        $this->assertTrue($this->manager->can('expense.create'));
        $this->assertFalse($this->manager->can('expense.delete'));
    }
}
