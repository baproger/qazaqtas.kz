<?php

namespace Tests\Feature;

use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StageRenameTest extends TestCase
{
    use RefreshDatabase;

    public function test_renamed_stage_reflected_via_translated_name(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $stage = DealStage::where('name', 'Договор')->first();

        $this->actingAs($admin)->put(route('stages.update', ['deal', $stage->id]), ['name' => 'Контракт'])->assertRedirect();

        $fresh = DealStage::with('translations')->find($stage->id);
        $this->assertEquals('Контракт', $fresh->name);
        $this->assertEquals('Контракт', $fresh->translatedName('ru'));
    }

    public function test_new_stage_visible_via_translated_name(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->post(route('stages.store'), ['kind' => 'deal', 'name' => 'Аванс'])->assertRedirect();

        $stage = DealStage::with('translations')->where('name', 'Аванс')->first();
        $this->assertEquals('Аванс', $stage->translatedName('ru'));
    }

    public function test_delete_reindexes_remaining_stages(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole("admin");

        $second = DealStage::orderBy("order")->skip(1)->first();
        $this->actingAs($admin)->delete(route("stages.destroy", ["deal", $second->id]))->assertRedirect();

        $orders = DealStage::orderBy("order")->pluck("order")->all();
        $this->assertEquals(range(1, count($orders)), $orders);
    }

    public function test_special_stage_found_by_type_survives_rename(): void
    {
        // Спец-логика держится на stage_type: переименование этапа её не ломает.
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $stage = DealStage::where('name', 'Переговоры')->first();
        $this->actingAs($admin)->put(route('stages.update', ['deal', $stage->id]), [
            'stage_type' => 'act', 'gate_task_title' => 'Выставить акт', 'gate_task_role' => 'financist', 'gate_task_days' => 3,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($stage->id, DealStage::actStage()?->id);

        // Переименовали как угодно — тип и гейт остались, логика жива.
        $this->actingAs($admin)->put(route('stages.update', ['deal', $stage->id]), ['name' => 'Финальная проверка'])->assertRedirect();
        $this->assertSame($stage->id, DealStage::actStage()?->id);
        $this->assertTrue(DealStage::find($stage->id)->hasGate());
    }

    public function test_stage_type_unique_per_funnel(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        [$a, $b] = DealStage::orderBy('order')->take(2)->get();
        $this->actingAs($admin)->put(route('stages.update', ['deal', $a->id]), ['stage_type' => 'esf'])->assertSessionHasNoErrors();
        // Второй «ЭСФ» в той же воронке — ошибка валидации.
        $this->actingAs($admin)->put(route('stages.update', ['deal', $b->id]), ['stage_type' => 'esf'])
            ->assertSessionHasErrors('stage_type');
    }

    public function test_payment_won_type_syncs_is_won(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $stage = DealStage::where('name', 'Договор')->first();
        // У сидера won — «Оплачено»; сначала снимем тип с него, чтобы не нарушить уникальность.
        $won = DealStage::where('stage_type', 'payment_won')->first();
        $this->actingAs($admin)->put(route('stages.update', ['deal', $won->id]), ['stage_type' => ''])->assertSessionHasNoErrors();
        $this->assertFalse((bool) $won->fresh()->is_won);

        $this->actingAs($admin)->put(route('stages.update', ['deal', $stage->id]), ['stage_type' => 'payment_won'])->assertSessionHasNoErrors();
        $this->assertTrue((bool) $stage->fresh()->is_won);
        $this->assertSame($stage->id, DealStage::wonStage()?->id);
    }

    public function test_delete_stage_with_active_deals_requires_transfer(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        [$a, $b] = DealStage::orderBy('order')->take(2)->get();
        $deal = \App\Models\Deal::create([
            'number' => 'BAIA-S-1', 'name' => 'Т', 'budget' => 1000, 'status' => 'active',
            'deal_stage_id' => $a->id, 'responsible_user_id' => $admin->id,
        ]);

        // Без переноса — ошибка, этап и сделка на месте.
        $this->actingAs($admin)->delete(route('stages.destroy', ['deal', $a->id]))
            ->assertSessionHasErrors('transfer_to');
        $this->assertNotNull(DealStage::find($a->id));

        // С переносом — сделка переезжает, этап удаляется.
        $this->actingAs($admin)->delete(route('stages.destroy', ['deal', $a->id]), ['transfer_to' => $b->id])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNull(DealStage::find($a->id));
        $this->assertSame($b->id, $deal->fresh()->deal_stage_id);
    }
}
