<?php

namespace Tests\Feature;

use App\Models\DdsEntry;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ДДС — ручная сводка на Финансах: редактируют admin/financist,
 * менеджер и цех не имеют доступа; данные не связаны с расчётами.
 */
class DdsTest extends TestCase
{
    use RefreshDatabase;

    public function test_financist_manages_dds_entries(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $fin = User::factory()->create();
        $fin->assignRole('financist');

        // Счёт компании.
        $this->actingAs($fin)->post(route('finance.dds.store'), [
            'kind' => 'account', 'name' => 'Baia Holding', 'bank' => 'народный',
            'balance' => 799000, 'receivable' => 31998202,
        ])->assertSessionHasNoErrors()->assertRedirect();
        // Долг.
        $this->actingAs($fin)->post(route('finance.dds.store'), [
            'kind' => 'debt', 'name' => 'Мирас', 'amount' => 865505,
        ])->assertRedirect();
        $this->assertSame(2, DdsEntry::count());

        // Правка и удаление.
        $row = DdsEntry::where('kind', 'account')->first();
        $this->actingAs($fin)->put(route('finance.dds.update', $row->id), [
            'kind' => 'account', 'name' => 'Baia Holding', 'bank' => 'Форте', 'balance' => 100,
        ])->assertRedirect();
        $this->assertSame('Форте', $row->fresh()->bank);

        $this->actingAs($fin)->delete(route('finance.dds.destroy', $row->id))->assertRedirect();
        $this->assertSame(1, DdsEntry::count());

        // Дата сводки.
        $this->actingAs($fin)->post(route('finance.dds.date'), ['dds_date' => '20.07.2026'])->assertRedirect();
        $this->assertSame('20.07.2026', \App\Models\Setting::get('dds_date'));
    }

    public function test_manager_cannot_edit_dds(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $mgr = User::factory()->create();
        $mgr->assignRole('manager');

        $this->actingAs($mgr)->post(route('finance.dds.store'), ['kind' => 'debt', 'name' => 'X', 'amount' => 1])
            ->assertForbidden();
    }

    public function test_dds_visible_on_finance_page_for_leadership(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $director = User::factory()->create();
        $director->assignRole('director');
        DdsEntry::create(['kind' => 'account', 'name' => 'Асу Плюс', 'bank' => 'Форте', 'balance' => 2777298.34]);

        $page = $this->actingAs($director)->get(route('finance.index'));
        $page->assertOk();
        $dds = $page->viewData('page')['props']['dds'];
        $this->assertSame('Асу Плюс', $dds['accounts'][0]['name']);
    }
}
