<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Brigade;
use App\Models\Company;
use App\Models\Expense;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Журнал показывает, ЧТО именно ввели.
 *
 * Раньше при создании записи в журнале стояло голое «создано»: сумму, дату и
 * кому — приходилось искать по самой записи, а у удалённой искать негде.
 * Теперь создание и удаление пишут снимок всех полей.
 */
class AuditSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $this->admin = User::factory()->create(['name' => 'Владелец']);
        $this->admin->assignRole('admin');
        $this->admin->companies()->attach(Company::where('code', 'QT')->value('id'));
    }

    private function snapshotOf(string $table, string $action): array
    {
        $log = AuditLog::where('table_name', $table)->where('action', $action)
            ->where('field_name', AuditLog::SNAPSHOT)->latest('id')->firstOrFail();

        return json_decode((string) ($log->new_value ?? $log->old_value), true) ?? [];
    }

    /** Создание записи сохраняет все введённые поля. */
    public function test_creation_stores_what_was_entered(): void
    {
        $this->actingAs($this->admin);

        Expense::create([
            'expenseable_type' => 'deal', 'expenseable_id' => 1,
            'amount' => 40000, 'date' => '2026-08-10', 'status' => 'confirmed',
            'payment_method' => 'cash', 'description' => 'Цемент',
        ]);

        $snapshot = $this->snapshotOf('expenses', 'created');

        $this->assertSame('40000', (string) $snapshot['amount']);
        $this->assertSame('Цемент', $snapshot['description']);
        $this->assertSame('cash', $snapshot['payment_method']);
        // Пустые поля в снимок не идут — иначе журнал нечитаем.
        $this->assertArrayNotHasKey('file_path', $snapshot);
    }

    /** Удаление сохраняет снимок: у удалённой записи искать уже негде. */
    public function test_deletion_keeps_a_snapshot_of_what_was_lost(): void
    {
        $this->actingAs($this->admin);

        $expense = Expense::create([
            'expenseable_type' => 'deal', 'expenseable_id' => 1,
            'amount' => 15000, 'date' => '2026-08-11', 'status' => 'confirmed',
            'payment_method' => 'bank', 'description' => 'Доставка',
        ]);
        $expense->delete();

        $snapshot = $this->snapshotOf('expenses', 'deleted');

        $this->assertSame('15000', (string) $snapshot['amount']);
        $this->assertSame('Доставка', $snapshot['description']);
    }

    /** Журнал показывает снимок по-русски: поля, имена, деньги, даты. */
    public function test_the_journal_shows_the_snapshot_in_human_words(): void
    {
        $this->actingAs($this->admin);

        Expense::create([
            'expenseable_type' => 'deal', 'expenseable_id' => 1,
            'amount' => 40000, 'date' => '2026-08-10', 'status' => 'confirmed',
            'payment_method' => 'cash', 'employee_id' => $this->admin->id,
        ]);

        $this->get(route('audit.index'))->assertInertia(fn ($page) => $page
            ->where('logs.data', function ($rows) {
                $row = collect($rows)->first(fn ($r) => $r['snapshot'] !== []);
                $fields = collect($row['snapshot'])->pluck('value', 'label');

                return $fields['Сумма'] === '40 000 ₸'
                    && $fields['Способ оплаты'] === 'Наличные'
                    && $fields['Дата'] === '10.08.2026'
                    && $fields['Сотрудник'] === 'Владелец';
            }));
    }

    /** Наряд бригады тоже попадает в журнал — его вводят в модальном окне. */
    public function test_production_order_is_written_to_the_journal(): void
    {
        $this->actingAs($this->admin);

        $foreman = User::factory()->create();
        $brigade = Brigade::create(['name' => 'Бригада 1', 'foreman_id' => $foreman->id, 'is_active' => true]);
        WorkOrder::create([
            'brigade_id' => $brigade->id, 'date' => '2026-08-12',
            'product' => 'Плитка', 'status' => 'draft', 'created_by' => $foreman->id,
        ]);

        $this->assertSame('Плитка', $this->snapshotOf('work_orders', 'created')['product']);
        $this->assertSame('Бригада 1', $this->snapshotOf('brigades', 'created')['name']);
    }
}
