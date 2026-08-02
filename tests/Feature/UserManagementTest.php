<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('admin');
        return $u;
    }

    public function test_admin_can_create_employee_with_role(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('users.store'), [
            'name' => 'Иван Сотрудник', 'email' => 'ivan@baia.kz',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
            'role' => 'employee', 'is_active' => true,
        ])->assertRedirect();

        $user = User::where('email', 'ivan@baia.kz')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('employee'));
    }

    public function test_index_renders_and_employee_forbidden(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get(route('users.index'))->assertOk();

        $emp = User::factory()->create();
        $emp->assignRole('employee');
        $this->actingAs($emp)->get(route('users.index'))->assertForbidden();
    }

    public function test_financist_can_add_employee_but_not_admin(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $fin = \App\Models\User::factory()->create();
        $fin->assignRole('financist');

        $payload = fn (string $role, string $email) => [
            'name' => 'Новый сотрудник', 'email' => $email,
            'password' => 'secret123', 'password_confirmation' => 'secret123', 'role' => $role,
        ];

        // Менеджера — можно.
        $this->actingAs($fin)->post(route('users.store'), $payload('manager', 'new1@baia.kz'))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue(\App\Models\User::where('email', 'new1@baia.kz')->first()->hasRole('manager'));

        // Админа — нельзя (только действующий админ).
        $this->actingAs($fin)->post(route('users.store'), $payload('admin', 'new2@baia.kz'))
            ->assertForbidden();
    }

    public function test_financist_can_edit_and_deactivate_employee_but_not_admin(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $fin = \App\Models\User::factory()->create();
        $fin->assignRole('financist');
        $emp = \App\Models\User::factory()->create(['name' => 'Иван']);
        $emp->assignRole('manager');
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');

        // Правка и деактивация обычного сотрудника — можно.
        $this->actingAs($fin)->put(route('users.update', $emp->id), [
            'name' => 'Иван Обновлённый', 'email' => $emp->email, 'role' => 'manager',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Иван Обновлённый', $emp->fresh()->name);

        // Админа — нельзя: ни править (поля не должны измениться), ни деактивировать.
        $adminName = $admin->name;
        $this->actingAs($fin)->put(route('users.update', $admin->id), [
            'name' => 'Взлом', 'email' => $admin->email, 'role' => 'admin',
        ])->assertForbidden();
        $this->assertSame($adminName, $admin->fresh()->name);
        $this->actingAs($fin)->delete(route('users.destroy', $admin->id))->assertForbidden();

        $this->actingAs($fin)->delete(route('users.destroy', $emp->id))->assertRedirect();
        $this->assertFalse($emp->fresh()->is_active);
    }
}
