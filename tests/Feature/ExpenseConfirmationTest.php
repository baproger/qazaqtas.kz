<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ExpenseConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $financist;

    private Deal $deal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $company = Company::where('code', 'BAIA')->firstOrFail();
        $this->manager = User::factory()->create();
        $this->manager->assignRole('manager');
        $this->manager->companies()->attach($company->id);
        $this->financist = User::factory()->create();
        $this->financist->assignRole('financist');
        $this->financist->companies()->attach($company->id);

        $this->deal = Deal::create([
            'company_id' => $company->id, 'number' => 'BAIA-C-1', 'name' => 'Т', 'company_name' => 'ТОО',
            'client_name' => 'Шкаф', 'budget' => 100000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
            'responsible_user_id' => $this->manager->id,
        ]);
    }

    private function createPendingExpense(): Expense
    {
        $this->actingAs($this->manager)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $this->deal->id,
            'amount' => 5000, 'date' => now()->toDateString(), 'description' => 'Доставка',
        ]);

        return Expense::latest('id')->firstOrFail();
    }

    public function test_manager_expense_notifies_accountant_with_task(): void
    {
        $expense = $this->createPendingExpense();

        $this->assertSame('pending', $expense->status);
        // Задача бухгалтеру на сделке + уведомление.
        $task = Task::where('assignee_id', $this->financist->id)->first();
        $this->assertNotNull($task);
        $this->assertStringContainsString('Подтвердить расход #'.$expense->id, $task->title);
        $this->assertSame(1, $this->financist->notifications()->count());
    }

    public function test_financist_confirms_with_receipt_and_payment_method(): void
    {
        $expense = $this->createPendingExpense();

        $this->actingAs($this->financist)->patch(route('expenses.confirm', $expense->id), [
            'payment_method' => 'cash',
            'file' => UploadedFile::fake()->image('чек.jpg'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $expense->refresh();
        $this->assertSame('confirmed', $expense->status);
        $this->assertSame('cash', $expense->payment_method);
        $this->assertSame($this->financist->id, (int) $expense->confirmed_by);
        $this->assertNotNull($expense->file_path);
        // Задача бухгалтера закрыта, автор уведомлён.
        $this->assertSame(0, Task::where('assignee_id', $this->financist->id)->where('status', '!=', 'done')->count());
        $this->assertSame(1, $this->manager->notifications()->count());
    }

    public function test_confirm_requires_receipt(): void
    {
        $expense = $this->createPendingExpense();

        $this->actingAs($this->financist)->patch(route('expenses.confirm', $expense->id), [
            'payment_method' => 'bank',
        ])->assertSessionHasErrors('file');

        $this->assertSame('pending', $expense->fresh()->status);
    }

    public function test_manager_cannot_confirm(): void
    {
        $expense = $this->createPendingExpense();

        $this->actingAs($this->manager)->patch(route('expenses.confirm', $expense->id), [
            'payment_method' => 'cash',
        ])->assertForbidden();
    }

    public function test_accountant_expense_confirmed_immediately(): void
    {
        $this->actingAs($this->financist)->post(route('expenses.store'), [
            'expenseable_type' => 'deal', 'expenseable_id' => $this->deal->id,
            'amount' => 700, 'date' => now()->toDateString(), 'payment_method' => 'bank',
            'file' => UploadedFile::fake()->image('чек.jpg'),
        ])->assertSessionHasNoErrors();

        $expense = Expense::latest('id')->firstOrFail();
        $this->assertSame('confirmed', $expense->status);
        $this->assertSame('bank', $expense->payment_method);
    }
}
