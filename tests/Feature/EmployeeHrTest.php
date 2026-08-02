<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HR-фичи страницы «Сотрудники»: профиль, экспорт, дни рождения,
 * руководитель отдела и его уведомления.
 */
class EmployeeHrTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('admin');

        return $u;
    }

    public function test_profile_page_access(): void
    {
        $admin = $this->admin();
        $emp = User::factory()->create(['birth_date' => '1990-05-10', 'hired_at' => '2024-03-01']);
        $emp->assignRole('employee');
        $other = User::factory()->create();
        $other->assignRole('employee');

        // Руководство видит профиль любого сотрудника.
        $this->actingAs($admin)->get(route('users.show', $emp->id))->assertOk();
        // Сам сотрудник видит свой профиль…
        $this->actingAs($emp)->get(route('users.show', $emp->id))->assertOk();
        // …но не чужой.
        $this->actingAs($emp)->get(route('users.show', $other->id))->assertForbidden();
    }

    public function test_export_csv_for_leadership_only(): void
    {
        $admin = $this->admin();
        $emp = User::factory()->create(['name' => 'Экспортный Иван', 'phone' => '+7 777 123 45 67']);
        $emp->assignRole('employee');

        $response = $this->actingAs($admin)->get(route('users.export'));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Экспортный Иван', $csv);
        $this->assertStringContainsString('+7 777 123 45 67', $csv);

        $this->actingAs($emp)->get(route('users.export'))->assertForbidden();
    }

    public function test_user_store_saves_birth_date_and_hired_at(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Новичок', 'email' => 'hr@baia.kz',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role' => 'employee', 'birth_date' => '1995-07-24', 'hired_at' => '2026-07-01',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $u = User::where('email', 'hr@baia.kz')->first();
        $this->assertSame('1995-07-24', $u->birth_date->toDateString());
        $this->assertSame('2026-07-01', $u->hired_at->toDateString());
    }

    public function test_birthday_command_notifies_leadership_not_the_person(): void
    {
        $admin = $this->admin();
        $birthday = User::factory()->create(['birth_date' => now()->subYears(30)->toDateString()]);
        $birthday->assignRole('manager');
        $noBirthday = User::factory()->create(['birth_date' => now()->subYears(25)->addDays(30)->toDateString()]);
        $noBirthday->assignRole('manager');

        $this->artisan('users:notify-birthdays')->assertSuccessful();

        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame('birthday', $admin->notifications()->first()->data['type']);
        // Сам именинник уведомление не получает.
        $this->assertSame(0, $birthday->notifications()->count());
    }

    public function test_department_head_saved_and_notified_on_overdue_task(): void
    {
        $admin = $this->admin();
        $head = User::factory()->create();
        $head->assignRole('manager');
        $emp = User::factory()->create();
        $emp->assignRole('employee');

        // Руководитель отдела назначается через страницу «Отделы».
        $dept = Department::create(['name' => 'Цех', 'is_active' => true]);
        $this->actingAs($admin)->put(route('departments.update', $dept->id), [
            'name' => 'Цех', 'head_user_id' => $head->id, 'is_active' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($head->id, $dept->fresh()->head_user_id);

        $emp->update(['department_id' => $dept->id]);
        Task::create([
            'taskable_type' => 'user', 'taskable_id' => $emp->id,
            'title' => 'Просроченная задача', 'assignee_id' => $emp->id,
            'creator_id' => $admin->id, 'status' => 'todo', 'due_date' => now()->subDay(),
        ]);

        $this->artisan('tasks:notify-overdue')->assertSuccessful();

        // Исполнитель получил обычное уведомление, руководитель отдела — своё.
        $this->assertSame(1, $emp->notifications()->count());
        $this->assertSame(1, $head->notifications()->count());
        $this->assertSame('department_task_overdue', $head->notifications()->first()->data['type']);
    }
}
