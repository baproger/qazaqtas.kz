<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\FinanceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Корректировка кассы (инвентаризация): ТОЛЬКО админ задаёт фактический
 * остаток наличных; разница хранится в Setting и не трогает платежи/отчёты.
 */
class CashCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_sets_actual_cash(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $fin = User::factory()->create();
        $fin->assignRole('financist');

        // Обнуление кассы: фактический остаток 0.
        $this->actingAs($admin)->post(route('finance.cashCorrection'), ['actual' => 0])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(0.0, app(FinanceService::class)->companyBalances(null)['cash']);

        // Повторная корректировка: фактический 500 000 — касса ровно 500 000.
        $this->actingAs($admin)->post(route('finance.cashCorrection'), ['actual' => 500000])
            ->assertRedirect();
        $this->assertSame(500000.0, app(FinanceService::class)->companyBalances(null)['cash']);
        $this->assertNotNull(Setting::get('cash_correction'));

        // История: каждая корректировка зафиксирована в аудите (кто и что менял).
        $audit = \App\Models\AuditLog::where('field_name', 'Корректировка кассы (фактический остаток)')->get();
        $this->assertSame(2, $audit->count());
        $this->assertSame($admin->id, $audit->last()->user_id);
        $this->assertSame('500000', $audit->last()->new_value);

        // Финансист и менеджер кассу НЕ корректируют — только админ.
        $this->actingAs($fin)->post(route('finance.cashCorrection'), ['actual' => 1])->assertForbidden();
        $mgr = User::factory()->create();
        $mgr->assignRole('manager');
        $this->actingAs($mgr)->post(route('finance.cashCorrection'), ['actual' => 1])->assertForbidden();
    }
}
