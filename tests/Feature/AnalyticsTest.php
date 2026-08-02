<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_renders_for_admin(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('admin');

        $this->actingAs($u)->get(route('analytics.index'))->assertOk();
    }

    public function test_analytics_forbidden_for_employee(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('employee');

        $this->actingAs($u)->get(route('analytics.index'))->assertForbidden();
    }

    public function test_analytics_renders_for_financist(): void
    {
        // Financist видел бывший Дашборд — после слияния должен видеть Аналитику.
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('financist');

        $this->actingAs($u)->get(route('analytics.index'))->assertOk();
    }

    public function test_analytics_renders_with_filters(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('admin');

        $this->actingAs($u)->get(route('analytics.index', [
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
            'manager' => $u->id,
            'stage' => 1,
            'search' => 'ТОО',
            'months' => 3,
        ]))->assertOk();
    }

    public function test_dashboard_redirects_by_role(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('analytics.index'));

        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $this->actingAs($manager)->get(route('dashboard'))->assertRedirect(route('deals.index'));

        $employee = User::factory()->create();
        $employee->assignRole('employee');
        $this->actingAs($employee)->get(route('dashboard'))->assertRedirect(route('projects.index'));
    }
}
