<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * «Расходы» — рабочее место бухгалтера: очередь на проверку и оплаченные.
 */
class ExpensesBoardTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $this->category = ExpenseCategory::create(['name' => 'Канцтовары', 'is_active' => true]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->companies()->attach(Company::where('code', 'QT')->value('id'));

        return $user;
    }

    private function request(User $author, array $extra = []): Expense
    {
        return Expense::create(array_merge([
            'responsible_user_id' => $author->id,
            'company_id' => Company::where('code', 'QT')->value('id'),
            'category_id' => $this->category->id,
            'amount' => 5000,
            'date' => now()->toDateString(),
            'description' => 'бензин',
            'status' => 'pending',
            'type' => 'direct',
        ], $extra));
    }

    /** Очередь бухгалтера не режется месяцем: старая заявка ждёт на виду. */
    public function test_pending_queue_is_not_cut_by_the_month(): void
    {
        $worker = $this->staff('employee');
        $old = $this->request($worker, ['date' => now()->subMonths(4)->toDateString()]);
        $fresh = $this->request($worker);

        $this->actingAs($this->staff('financist'))->get(route('expensesBoard.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Finance/ExpensesBoard')
                ->has('pending', 2)
                // Старые сверху: кто ждёт дольше, тот и первый.
                ->where('pending.0.id', $old->id)
                ->where('pending.1.id', $fresh->id)
                ->where('pendingTotal', 10000));
    }

    /** Оплаченные месяц фильтрует и отдаёт страницами. */
    public function test_paid_are_filtered_by_the_month_and_paginated(): void
    {
        $worker = $this->staff('employee');
        $accountant = $this->staff('financist');
        $confirmed = ['status' => 'confirmed', 'payment_method' => 'cash', 'confirmed_by' => $accountant->id, 'confirmed_at' => now()];

        $this->request($worker, $confirmed);
        $this->request($worker, $confirmed + ['date' => now()->subMonths(2)->toDateString()]);

        $this->actingAs($accountant)->get(route('expensesBoard.index'))
            ->assertInertia(fn ($page) => $page
                ->has('paid.data', 1)
                ->where('paidTotal', 5000)
                ->has('paid.links'));
    }

    /** Директор смотрит очередь, но кнопок подтверждения не получает. */
    public function test_director_watches_without_buttons(): void
    {
        $this->request($this->staff('employee'));

        $this->actingAs($this->staff('director'))->get(route('expensesBoard.index'))
            ->assertInertia(fn ($page) => $page->has('pending', 1)->where('canConfirm', false));

        $this->actingAs($this->staff('financist'))->get(route('expensesBoard.index'))
            ->assertInertia(fn ($page) => $page->where('canConfirm', true));
    }

    /** Менеджеру и цеховому рабочее место бухгалтера закрыто. */
    public function test_board_is_closed_for_staff(): void
    {
        $this->actingAs($this->staff('manager'))->get(route('expensesBoard.index'))->assertForbidden();
        $this->actingAs($this->staff('employee'))->get(route('expensesBoard.index'))->assertForbidden();
    }

    /**
     * Подтверждение с борда — тот же endpoint, что в панели сделки: расход
     * уходит из очереди, способ оплаты и подтвердивший записаны.
     */
    public function test_confirmation_from_the_board_uses_the_same_endpoint(): void
    {
        $worker = $this->staff('employee');
        $accountant = $this->staff('financist');
        $expense = $this->request($worker);

        $this->actingAs($accountant)->patch(route('expenses.confirm', $expense->id), [
            'payment_method' => 'bank',
            'file' => UploadedFile::fake()->image('чек.jpg'),
        ])->assertSessionHasNoErrors();

        $expense->refresh();
        $this->assertSame('confirmed', $expense->status);
        $this->assertSame('bank', $expense->payment_method);
        $this->assertSame($accountant->id, $expense->confirmed_by);

        $this->actingAs($accountant)->get(route('expensesBoard.index'))
            ->assertInertia(fn ($page) => $page->has('pending', 0)->has('paid.data', 1));
    }

    /** Расход по сделке тоже ждёт в очереди — со ссылкой, откуда он. */
    public function test_deal_expense_shows_its_source(): void
    {
        $manager = $this->staff('manager');
        $deal = Deal::create([
            'company_id' => Company::where('code', 'QT')->value('id'),
            'number' => 'QT-1', 'name' => 'Сделка', 'company_name' => 'Клиент',
            'budget' => 100000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->value('id'),
            'responsible_user_id' => $manager->id,
        ]);

        $expense = $this->request($manager, [
            'company_id' => null,
            'expenseable_type' => 'deal',
            'expenseable_id' => $deal->id,
        ]);

        $this->actingAs($this->staff('financist'))->get(route('expensesBoard.index'))
            ->assertInertia(fn ($page) => $page
                ->has('pending', 1)
                ->where('pending.0.id', $expense->id)
                ->where('pending.0.source.number', 'QT-1'));
    }

    /** Бухгалтер заводит расход и правит категории прямо на этой странице. */
    public function test_board_gives_the_accountant_the_expense_form(): void
    {
        $this->actingAs($this->staff('financist'))->get(route('expensesBoard.index'))
            ->assertInertia(fn ($page) => $page
                ->where('canConfirm', true)
                ->has('categories')
                ->has('balances.cash')
                ->has('balances.bank'));
    }

    /** У расхода по сделке видно, по какой именно: номер и заказчик. */
    public function test_paid_expense_shows_its_deal(): void
    {
        $manager = $this->staff('manager');
        $deal = Deal::create([
            'company_id' => Company::where('code', 'QT')->value('id'),
            'number' => 'QT-7', 'name' => 'Сделка', 'company_name' => 'Асхат',
            'budget' => 100000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->value('id'),
            'responsible_user_id' => $manager->id,
        ]);
        $accountant = $this->staff('financist');

        $this->request($manager, [
            'company_id' => null,
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'status' => 'confirmed', 'payment_method' => 'cash',
            'confirmed_by' => $accountant->id, 'confirmed_at' => now(),
        ]);

        $this->actingAs($accountant)->get(route('expensesBoard.index'))
            ->assertInertia(fn ($page) => $page
                ->where('paid.data.0.source.number', 'QT-7')
                ->where('paid.data.0.source.title', 'Асхат'));
    }

    /** Тип чека виден заранее: картинку карточка открывает, PDF даёт ссылкой. */
    public function test_receipt_kind_is_reported(): void
    {
        $worker = $this->staff('employee');
        $this->request($worker, ['file_path' => 'receipts/чек.jpg']);
        $this->request($worker, ['file_path' => 'receipts/счёт.pdf', 'amount' => 100]);
        $this->request($worker, ['amount' => 200]);

        $this->actingAs($this->staff('financist'))->get(route('expensesBoard.index'))
            ->assertInertia(fn ($page) => $page
                ->where('pending.0.receipt.kind', 'image')
                ->where('pending.1.receipt.kind', 'pdf')
                ->where('pending.2.receipt', null));
    }
}
