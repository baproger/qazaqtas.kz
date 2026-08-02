<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditFormatter;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class HistoryAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_workshop_employee_cannot_open_deals_page(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $emp = User::factory()->create();
        $emp->assignRole('employee');

        $this->actingAs($emp)->get(route('deals.index'))->assertForbidden();
    }

    public function test_history_resolves_stage_ids_to_names(): void
    {
        $log = new AuditLog([
            'table_name' => 'deals', 'record_id' => 1, 'action' => 'updated',
            'field_name' => 'deal_stage_id', 'old_value' => '4', 'new_value' => '5',
        ]);

        $result = AuditFormatter::humanize(
            new Collection([$log]),
            ['deal_stage_id' => new Collection([4 => 'Договор', 5 => 'Оплачено'])]
        );

        $this->assertEquals('Договор', $result->first()->old_value);
        $this->assertEquals('Оплачено', $result->first()->new_value);
    }
}
