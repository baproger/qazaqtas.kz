<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Персональный код входа ключевых сотрудников.
 *
 * Код выдаёт только администратор; вход у сотрудника с кодом — в два шага
 * (пароль, затем код), у остальных — как раньше, только по паролю.
 */
class AccessCodeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('admin');

        return $u;
    }

    private function keyEmployee(string $code = '123456'): User
    {
        $u = User::factory()->create(['password' => Hash::make('secret-pass')]);
        $u->forceFill(['access_code' => Hash::make($code), 'access_code_issued_at' => now()])->save();

        return $u;
    }

    public function test_user_without_code_logs_in_with_password_only(): void
    {
        $u = User::factory()->create(['password' => Hash::make('secret-pass')]);

        $this->post(route('login'), ['email' => $u->email, 'password' => 'secret-pass'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($u);
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $u = User::factory()->create(['password' => Hash::make('secret-pass'), 'is_active' => false]);

        $this->post(route('login'), ['email' => $u->email, 'password' => 'secret-pass'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_key_employee_is_sent_to_code_step_and_stays_guest(): void
    {
        $u = $this->keyEmployee();

        $this->post(route('login'), ['email' => $u->email, 'password' => 'secret-pass'])
            ->assertRedirect(route('login.code'));
        // Пароль пройден, но сессия всё ещё гостевая — код не введён.
        $this->assertGuest();
    }

    public function test_correct_code_completes_login(): void
    {
        $u = $this->keyEmployee('654321');

        $this->post(route('login'), ['email' => $u->email, 'password' => 'secret-pass']);
        $this->post(route('login.code.store'), ['code' => '654321'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($u);
    }

    public function test_wrong_code_keeps_guest_and_throttles(): void
    {
        $u = $this->keyEmployee('654321');

        $this->post(route('login'), ['email' => $u->email, 'password' => 'secret-pass']);
        foreach (range(1, 5) as $i) {
            $this->post(route('login.code.store'), ['code' => '000000'])->assertSessionHasErrors('code');
        }
        $this->assertGuest();

        // Шестая попытка — блокировка, даже с верным кодом.
        $this->post(route('login.code.store'), ['code' => '654321'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_code_page_without_pending_login_redirects_to_login(): void
    {
        $this->get(route('login.code'))->assertRedirect(route('login'));
        $this->post(route('login.code.store'), ['code' => '123456'])->assertRedirect(route('login'));
    }

    public function test_admin_issues_and_revokes_code(): void
    {
        $admin = $this->admin();
        $employee = User::factory()->create();

        $this->actingAs($admin)->post(route('users.accessCode.issue', $employee))->assertRedirect();
        $employee->refresh();
        $this->assertNotNull($employee->access_code);
        $this->assertNotNull($employee->access_code_issued_at);
        // Код показывается один раз через flash и подходит к своему хэшу.
        $shown = session('issued_access_code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $shown);
        $this->assertTrue(Hash::check($shown, $employee->access_code));

        // Карточка сотрудника рендерится с выданным кодом (регрессия: без
        // datetime-каста access_code_issued_at страница падала на 500).
        $this->actingAs($admin)->get(route('users.show', $employee))->assertOk();

        $this->actingAs($admin)->delete(route('users.accessCode.revoke', $employee))->assertRedirect();
        $this->assertNull($employee->refresh()->access_code);
    }

    public function test_only_admin_manages_codes(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $director = User::factory()->create();
        $director->assignRole('director');
        $employee = User::factory()->create();

        $this->actingAs($director)->post(route('users.accessCode.issue', $employee))->assertForbidden();
        $this->actingAs($director)->delete(route('users.accessCode.revoke', $employee))->assertForbidden();
    }
}
