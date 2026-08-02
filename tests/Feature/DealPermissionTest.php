<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DealPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function deal(?int $responsibleId = null): Deal
    {
        return Deal::create([
            'number' => 'BAIA-P-'.(Deal::count() + 1), 'name' => 'D', 'budget' => 1000, 'status' => 'active',
            'responsible_user_id' => $responsibleId,
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    public function test_responsible_without_permission_can_edit_deal(): void
    {
        $user = User::factory()->create(); // no role → no deal.update permission
        $this->assertFalse($user->can('deal.update'));

        $deal = $this->deal($user->id);

        $this->actingAs($user)->put(route('deals.update', $deal), [
            'client_name' => 'Иван', 'company_name' => 'ТОО Отредактировано', 'address' => 'Алматы', 'budget' => 2000,
        ])->assertRedirect();

        $fresh = $deal->fresh();
        $this->assertEquals('ТОО Отредактировано', $fresh->company_name);
        // Название сделки зеркалит название компании (поле убрано из UI).
        $this->assertEquals('ТОО Отредактировано', $fresh->name);
    }

    public function test_long_contract_number_saves(): void
    {
        // Регрессия prod 17.07: bin был varchar(20) → «990440002867/260024/00»
        // (22+ символов) падал с 1406 Data too long при создании/правке.
        $user = User::factory()->create();
        $deal = $this->deal($user->id);
        $longBin = '990440002867/260024/00-ДОП1';

        $this->actingAs($user)->put(route('deals.update', $deal), [
            'client_name' => 'товар', 'company_name' => 'ТОО', 'address' => 'Алматы',
            'budget' => 1000, 'bin' => $longBin,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals($longBin, $deal->fresh()->bin);
    }

    public function test_non_responsible_without_permission_cannot_edit(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $deal = $this->deal($owner->id);

        $this->actingAs($stranger)->put(route('deals.update', $deal), [
            'name' => 'Хакнуто', 'client_name' => 'И', 'company_name' => 'Х', 'address' => 'Алматы', 'budget' => 1,
        ])->assertForbidden();
    }

    public function test_leadership_can_change_responsible(): void
    {
        // (Re)assigning the responsible person is an "update" — leadership with
        // deal.update (financist/admin) may do it. Директор — наблюдатель без
        // права правки (см. test ниже).
        $lead = User::factory()->create();
        $lead->assignRole('financist');
        $newResp = User::factory()->create();
        $deal = $this->deal(null);

        $this->actingAs($lead)->patch(route('deals.responsible', $deal), [
            'responsible_user_id' => $newResp->id,
        ])->assertRedirect();

        $this->assertEquals($newResp->id, $deal->fresh()->responsible_user_id);
    }

    public function test_director_is_observer_cannot_change_responsible(): void
    {
        // Директор — наблюдатель: правка сделки (в т.ч. смена ответственного)
        // ему недоступна (закрытие эскалации прав из аудита).
        $director = User::factory()->create();
        $director->assignRole('director');
        $deal = $this->deal(null);

        $this->actingAs($director)->patch(route('deals.responsible', $deal), [
            'responsible_user_id' => User::factory()->create()->id,
        ])->assertForbidden();
    }

    public function test_non_owner_manager_cannot_change_responsible(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $deal = $this->deal(null); // not owned by $manager

        $this->actingAs($manager)->patch(route('deals.responsible', $deal), [
            'responsible_user_id' => $manager->id,
        ])->assertForbidden();
    }

    public function test_only_admin_can_delete_deal(): void
    {
        // Удаление сделки — только админ: даже ответственный менеджер,
        // директор и бухгалтер получают 403.
        foreach (['manager', 'director', 'financist'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $deal = $this->deal($user->id);

            $this->actingAs($user)->delete(route('deals.destroy', $deal))->assertForbidden();
            $this->assertNotNull($deal->fresh(), "Роль {$role} не должна удалять сделку");
        }

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $deal = $this->deal(null);

        $this->actingAs($admin)->delete(route('deals.destroy', $deal))->assertRedirect();
        $this->assertNull(Deal::find($deal->id));
    }
}
