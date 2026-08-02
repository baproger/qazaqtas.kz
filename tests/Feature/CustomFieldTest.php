<?php

namespace Tests\Feature;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use App\Services\CustomFieldService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomFieldTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        $u = User::factory()->create();
        $u->assignRole('admin');
        return $u;
    }

    public function test_manage_definition_and_set_value(): void
    {
        $u = $this->admin();

        $this->actingAs($u)->post(route('custom-fields.store'), [
            'entity_type' => 'deal', 'name' => 'Источник', 'type' => 'select', 'options' => ['Сайт', 'Звонок'],
        ])->assertRedirect();

        $field = CustomField::first();
        $this->assertEquals('deal', $field->entity_type);

        $deal = Deal::create([
            'number' => 'BAIA-CF-1', 'name' => 'D', 'budget' => 1, 'status' => 'active',
            'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);

        $this->actingAs($u)->post(route('custom-field-values.sync'), [
            'entity_type' => 'deal', 'entity_id' => $deal->id,
            'values' => [$field->id => 'Сайт'],
        ])->assertRedirect();

        $this->assertDatabaseHas('custom_field_values', [
            'custom_field_id' => $field->id, 'entity_type' => 'deal', 'entity_id' => $deal->id, 'value' => 'Сайт',
        ]);

        $merged = app(CustomFieldService::class)->forEntity('deal', $deal->id);
        $this->assertEquals('Сайт', $merged[0]['value']);
    }

    public function test_settings_page_renders(): void
    {
        $u = $this->admin();
        $this->actingAs($u)->get(route('custom-fields.index'))->assertOk();
    }
}
