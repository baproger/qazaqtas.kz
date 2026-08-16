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
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Заявка «Расход компании» от любого сотрудника — счёт бухгалтеру на оплату.
 */
class CompanyExpenseRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->accountant = User::factory()->create();
        $this->accountant->assignRole('financist');
        $this->accountant->companies()->attach(Company::where('code', 'QT')->value('id'));

        $this->category = ExpenseCategory::create(['name' => 'Канцтовары', 'is_active' => true]);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->companies()->attach(Company::where('code', 'QT')->value('id'));

        return $user;
    }

    private function payload(array $extra = []): array
    {
        return array_merge([
            'category_id' => $this->category->id,
            'amount' => 2000,
            'date' => now()->toDateString(),
            'description' => 'блокноты',
        ], $extra);
    }

    /** Цеховой подаёт заявку: она ждёт бухгалтера и не трогает кассу. */
    public function test_worker_submits_a_request_that_waits_for_the_accountant(): void
    {
        $worker = $this->staff('employee');

        $this->actingAs($worker)->post(route('expenses.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $expense = Expense::firstOrFail();

        $this->assertSame('pending', $expense->status);
        $this->assertNull($expense->payment_method, 'Откуда платить — решает бухгалтер.');
        $this->assertNull($expense->confirmed_by);
        $this->assertSame($worker->id, $expense->responsible_user_id);
    }

    /**
     * Менеджеру заявку перекрывала проверка «расход только по своей сделке»:
     * у расхода компании сделки нет, и правило срабатывало на пустоте.
     */
    public function test_manager_can_submit_a_company_request(): void
    {
        $manager = $this->staff('manager');

        $this->actingAs($manager)->post(route('expenses.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertSame('pending', Expense::firstOrFail()->status);
    }

    /** Менеджер по-прежнему не заводит расход на ЧУЖУЮ сделку. */
    public function test_manager_still_cannot_touch_another_managers_deal(): void
    {
        $owner = $this->staff('manager');
        $stranger = $this->staff('manager');

        $deal = Deal::create([
            'company_id' => Company::where('code', 'QT')->value('id'),
            'number' => 'QT-900', 'name' => 'Ч', 'company_name' => 'Чужая',
            'budget' => 100000, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->value('id'),
            'responsible_user_id' => $owner->id,
        ]);

        $this->actingAs($stranger)->post(route('expenses.store'), $this->payload([
            'expenseable_type' => 'deal',
            'expenseable_id' => $deal->id,
        ]))->assertForbidden();
    }

    /**
     * Статус и способ оплаты ставит сервер: подделать их в форме нельзя,
     * иначе касса уменьшалась бы до того, как деньги реально ушли.
     */
    public function test_status_and_payment_method_cannot_be_forged(): void
    {
        $worker = $this->staff('employee');

        $this->actingAs($worker)->post(route('expenses.store'), $this->payload([
            'status' => 'confirmed',
            'payment_method' => 'cash',
        ]))->assertSessionHasNoErrors();

        $expense = Expense::firstOrFail();

        $this->assertSame('pending', $expense->status);
        $this->assertNull($expense->payment_method);
    }

    /** Бухгалтер вводит расход компании как раньше — сразу оплаченным. */
    public function test_accountant_still_enters_confirmed_expense(): void
    {
        $this->actingAs($this->accountant)->post(route('expenses.store'), $this->payload([
            'payment_method' => 'cash',
        ]))->assertSessionHasNoErrors();

        $expense = Expense::firstOrFail();

        $this->assertSame('confirmed', $expense->status);
        $this->assertSame('cash', $expense->payment_method);
    }

    public function test_category_is_required(): void
    {
        $worker = $this->staff('employee');

        $this->actingAs($worker)->post(route('expenses.store'), $this->payload(['category_id' => null]))
            ->assertSessionHasErrors('category_id');
    }

    /** Со склада списывают из карточки сделки, а не заявкой компании. */
    public function test_company_request_cannot_write_off_stock(): void
    {
        $worker = $this->staff('employee');
        $material = \App\Models\Material::create(['name' => 'Цемент', 'unit' => 'кг', 'quantity' => 10]);

        $this->actingAs($worker)->post(route('expenses.store'), $this->payload([
            'material_id' => $material->id,
            'qty' => 1,
        ]))->assertSessionHasErrors('material_id');
    }

    public function test_accountants_are_notified_about_the_request(): void
    {
        Notification::fake();
        $worker = $this->staff('employee');

        $this->actingAs($worker)->post(route('expenses.store'), $this->payload());

        Notification::assertSentTo(
            $this->accountant,
            \App\Notifications\CompanyExpenseSubmitted::class,
            fn ($n) => $n->toArray($this->accountant)['url'] === '/expenses-board',
        );
    }

    public function test_author_is_notified_when_the_request_is_paid(): void
    {
        $worker = $this->staff('employee');

        $this->actingAs($worker)->post(route('expenses.store'), $this->payload());
        $expense = Expense::firstOrFail();

        \Illuminate\Support\Facades\Storage::fake('local');
        Notification::fake();

        $this->actingAs($this->accountant)->patch(route('expenses.confirm', $expense->id), [
            'payment_method' => 'bank',
            'file' => \Illuminate\Http\UploadedFile::fake()->image('чек.jpg'),
        ])->assertSessionHasNoErrors();

        Notification::assertSentTo(
            $worker,
            \App\Notifications\CompanyExpensePaid::class,
            fn ($n) => $n->toArray($worker)['url'] === '/my-expenses',
        );
    }
}
