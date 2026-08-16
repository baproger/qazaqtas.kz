<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Выполненное действие само гасит красный счётчик.
 *
 * Счётчик, который не гаснет от работы, люди перестают читать — и пропускают
 * в нём настоящее.
 */
class NotificationResolverTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private User $accountant;

    private User $secondAccountant;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->worker = $this->staff('employee');
        $this->accountant = $this->staff('financist');
        $this->secondAccountant = $this->staff('financist');
        $this->category = ExpenseCategory::create(['name' => 'Канцтовары', 'is_active' => true]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->companies()->attach(Company::where('code', 'QT')->value('id'));

        return $user;
    }

    private function submitRequest(): Expense
    {
        $this->actingAs($this->worker)->post(route('expenses.store'), [
            'category_id' => $this->category->id,
            'amount' => 5000,
            'date' => now()->toDateString(),
            'description' => 'бензин',
        ])->assertSessionHasNoErrors();

        return Expense::latest('id')->firstOrFail();
    }

    /** Подтверждение расхода гасит «ждёт проверки» у ВСЕХ бухгалтеров. */
    public function test_confirmation_clears_the_pending_notifications(): void
    {
        $expense = $this->submitRequest();

        $this->assertSame(1, $this->accountant->unreadNotifications()->count());
        $this->assertSame(1, $this->secondAccountant->unreadNotifications()->count());

        $this->actingAs($this->accountant)->patch(route('expenses.confirm', $expense->id), [
            'payment_method' => 'cash',
            'file' => UploadedFile::fake()->image('чек.jpg'),
        ])->assertSessionHasNoErrors();

        // У второго бухгалтера «ждёт проверки» погасло; вместо него пришло
        // «расход уже подтверждён» — оно новое и остаётся непрочитанным.
        $stale = $this->secondAccountant->unreadNotifications()
            ->where('type', \App\Notifications\CompanyExpenseSubmitted::class)->count();
        $this->assertSame(0, $stale, 'Старое «ждёт проверки» должно погаснуть.');

        // Автору пришло «расход оплачен» — его гасить не за что.
        $this->assertSame(1, $this->worker->unreadNotifications()->count());
    }

    /** Удаление расхода тоже гасит уведомления о нём. */
    public function test_deletion_clears_the_notifications(): void
    {
        $expense = $this->submitRequest();
        $this->assertSame(1, $this->accountant->unreadNotifications()->count());

        $this->actingAs($this->accountant)->delete(route('expenses.destroy', $expense->id))
            ->assertSessionHasNoErrors();

        $left = $this->accountant->unreadNotifications()
            ->where('type', \App\Notifications\CompanyExpenseSubmitted::class)->count();
        $this->assertSame(0, $left);
    }

    /** Закрытая задача гасит свои уведомления у исполнителя. */
    public function test_closing_a_task_clears_its_notifications(): void
    {
        // Задачи заводит тот, у кого есть право их создавать (у бухгалтера
        // его нет — он ведёт деньги, а не поручения).
        $this->actingAs($this->staff('admin'))->post(route('tasks.store'), [
            'title' => 'Позвонить клиенту', 'assignee_id' => $this->worker->id,
        ])->assertSessionHasNoErrors();

        $task = Task::firstOrFail();
        $this->assertSame(1, $this->worker->unreadNotifications()->count());

        $this->actingAs($this->worker)->patch(route('tasks.status', $task->id), ['status' => 'done'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $this->worker->unreadNotifications()->count());
    }

    /** Уведомление об удалении ведёт к хозяину записи, а не в «Финансы». */
    public function test_deletion_notification_links_to_the_owner(): void
    {
        $director = $this->staff('director');
        $deal = Deal::create([
            'company_id' => Company::where('code', 'QT')->value('id'),
            'number' => 'QT-2', 'name' => 'Сделка', 'company_name' => 'Клиент',
            'budget' => 100000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->value('id'),
            'responsible_user_id' => $this->accountant->id,
        ]);
        $expense = Expense::create([
            'company_id' => Company::where('code', 'QT')->value('id'),
            'expenseable_type' => 'deal', 'expenseable_id' => $deal->id,
            'amount' => 7000, 'date' => now()->toDateString(),
            'status' => 'confirmed', 'payment_method' => 'cash',
            'responsible_user_id' => $this->accountant->id,
        ]);

        $this->actingAs($this->accountant)->delete(route('expenses.destroy', $expense->id))
            ->assertSessionHasNoErrors();

        $notification = $director->notifications()
            ->where('type', \App\Notifications\FinanceRecordDeleted::class)->firstOrFail();

        $this->assertSame('/deals/'.$deal->id, $notification->data['url']);
    }

    /** Поступление хозяина не имеет — ссылка ведёт на Финансы. */
    public function test_deletion_without_an_owner_links_to_finance(): void
    {
        $director = $this->staff('director');

        $this->actingAs($this->accountant)->post(route('finance.receipts.store'), [
            'amount' => 15000, 'method' => 'cash', 'source' => 'учредитель',
            'date' => now()->toDateString(),
        ])->assertSessionHasNoErrors();

        $receipt = \App\Models\CashReceipt::firstOrFail();
        $this->actingAs($this->accountant)->delete(route('finance.receipts.destroy', $receipt->id))
            ->assertSessionHasNoErrors();

        $notification = $director->notifications()
            ->where('type', \App\Notifications\FinanceRecordDeleted::class)->firstOrFail();

        $this->assertStringContainsString('finance', $notification->data['url']);
    }

    /** Заявка старше трёх дней напоминает о себе — и ровно один раз. */
    public function test_stale_request_reminds_once(): void
    {
        $expense = $this->submitRequest();
        $expense->forceFill(['created_at' => now()->subDays(4)])->save();

        // Уведомление о подаче уже есть — считаем только напоминания.
        $this->artisan('expenses:notify-stale')->assertSuccessful();
        $reminders = fn () => $this->accountant->notifications()
            ->where('type', \App\Notifications\CompanyExpenseStale::class)->count();

        $this->assertSame(1, $reminders());
        $this->assertNotNull($expense->fresh()->reminded_at);

        $this->artisan('expenses:notify-stale')->assertSuccessful();
        $this->assertSame(1, $reminders(), 'Повтор напоминания — шум, который перестают читать.');
    }

    /** Свежая заявка не напоминает: у бухгалтера есть три дня. */
    public function test_fresh_request_is_not_reported(): void
    {
        $this->submitRequest();

        $this->artisan('expenses:notify-stale')->assertSuccessful();

        $this->assertSame(0, $this->accountant->notifications()
            ->where('type', \App\Notifications\CompanyExpenseStale::class)->count());
    }

    /** Оплата заявки гасит и напоминание о ней. */
    public function test_paying_the_request_clears_the_reminder(): void
    {
        $expense = $this->submitRequest();
        $expense->forceFill(['created_at' => now()->subDays(4)])->save();
        $this->artisan('expenses:notify-stale')->assertSuccessful();

        $this->actingAs($this->accountant)->patch(route('expenses.confirm', $expense->id), [
            'payment_method' => 'bank',
            'file' => UploadedFile::fake()->image('чек.jpg'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, $this->accountant->unreadNotifications()
            ->where('type', \App\Notifications\CompanyExpenseStale::class)->count());
    }
}
