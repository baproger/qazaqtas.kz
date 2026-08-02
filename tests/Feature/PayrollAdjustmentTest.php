<?php

namespace Tests\Feature;

use App\Models\PayrollAdjustment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, float $salary = 0): User
    {
        $u = User::factory()->create(['salary' => $salary]);
        $u->assignRole($role);

        return $u;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    public function test_financist_adds_absence_with_auto_amount_from_days(): void
    {
        $fin = $this->user('financist');
        $mgr = $this->user('manager', 220000); // оклад 220 000 → день = 10 000

        $this->actingAs($fin)->post(route('payroll.adjustments.store'), [
            'user_id' => $mgr->id, 'type' => 'absence', 'days' => 2, 'date' => now()->toDateString(),
        ])->assertRedirect();

        $adj = PayrollAdjustment::first();
        $this->assertEquals(20000.0, (float) $adj->amount); // 220000 / 22 × 2
        $this->assertEquals($fin->id, $adj->created_by);
    }

    public function test_manager_cannot_add_adjustment_or_salary(): void
    {
        $mgr = $this->user('manager', 100000);

        $this->actingAs($mgr)->post(route('payroll.adjustments.store'), [
            'user_id' => $mgr->id, 'type' => 'bonus', 'amount' => 100, 'date' => now()->toDateString(),
        ])->assertForbidden();

        $this->actingAs($mgr)->patch(route('payroll.salary', $mgr->id), ['salary' => 999999])->assertForbidden();
    }

    public function test_financist_sets_salary_and_fine_reduces_payout(): void
    {
        $fin = $this->user('financist');
        $mgr = $this->user('manager');

        $this->actingAs($fin)->patch(route('payroll.salary', $mgr->id), ['salary' => 300000])->assertRedirect();
        $this->assertEquals(300000.0, (float) $mgr->fresh()->salary);

        PayrollAdjustment::create(['user_id' => $mgr->id, 'type' => 'fine', 'amount' => 50000, 'date' => now()->toDateString()]);
        // Аванс — тоже удержание: выдан вперёд, минусуется из ЗП.
        PayrollAdjustment::create(['user_id' => $mgr->id, 'type' => 'advance', 'amount' => 70000, 'date' => now()->toDateString()]);

        $this->actingAs($fin)->get(route('payroll.index'))
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('rows', fn ($rows) => collect($rows)
                ->contains(fn ($r) => $r['uid'] === $mgr->id && (float) $r['deductions'] === 120000.0
                    && (float) $r['final'] === 180000.0)));
    }

    public function test_adjustment_outside_month_not_counted(): void
    {
        $fin = $this->user('financist');
        $mgr = $this->user('manager', 100000);
        // NoOverflow: 31-е число минус месяц иначе переполняется в 1-е ТЕКУЩЕГО месяца.
        PayrollAdjustment::create(['user_id' => $mgr->id, 'type' => 'fine', 'amount' => 10000, 'date' => now()->subMonthNoOverflow()->toDateString()]);

        $this->actingAs($fin)->get(route('payroll.index'))
            ->assertInertia(fn ($p) => $p->where('rows', fn ($rows) => collect($rows)
                ->contains(fn ($r) => $r['uid'] === $mgr->id && (float) $r['deductions'] === 0.0)));
    }
}
