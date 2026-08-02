<?php

namespace Tests\Feature;

use App\Models\CustomField;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    private function deal(User $owner): Deal
    {
        return Deal::create(['number' => 'D-1', 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 1, 'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->first()->id, 'responsible_user_id' => $owner->id]);
    }

    public function test_cex_employee_cannot_reassign_responsible(): void
    {
        $mgr = $this->user('manager');
        $emp = $this->user('employee');
        $deal = $this->deal($mgr);

        $this->actingAs($emp)->patch(route('deals.responsible', $deal), ['responsible_user_id' => $emp->id])->assertForbidden();
        $this->assertEquals($mgr->id, $deal->fresh()->responsible_user_id);
    }

    public function test_cex_employee_cannot_write_deal_custom_fields(): void
    {
        $mgr = $this->user('manager');
        $emp = $this->user('employee');
        $deal = $this->deal($mgr);
        $field = CustomField::create(['entity_type' => 'deal', 'name' => 'БИН', 'type' => 'text']);

        $this->actingAs($emp)->post(route('custom-field-values.sync'), [
            'entity_type' => 'deal', 'entity_id' => $deal->id, 'values' => [$field->id => 'hack'],
        ])->assertForbidden();
    }

    public function test_cex_employee_cannot_comment_on_deal(): void
    {
        $mgr = $this->user('manager');
        $emp = $this->user('employee');
        $deal = $this->deal($mgr);

        $this->actingAs($emp)->post(route('comments.store'), [
            'commentable_type' => 'deal', 'commentable_id' => $deal->id, 'body' => 'spy',
        ])->assertForbidden();
    }

    public function test_owner_can_still_reassign(): void
    {
        $mgr = $this->user('manager');
        $deal = $this->deal($mgr);
        $newResp = $this->user('manager');

        $this->actingAs($mgr)->patch(route('deals.responsible', $deal), ['responsible_user_id' => $newResp->id])->assertRedirect();
        $this->assertEquals($newResp->id, $deal->fresh()->responsible_user_id);
    }
}
