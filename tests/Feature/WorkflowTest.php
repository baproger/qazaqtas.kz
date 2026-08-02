<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function seedAll(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->assignRole($role);
        return $u;
    }

    public function test_manager_can_create_deal(): void
    {
        $this->seedAll();
        $emp = $this->user('manager');

        $this->actingAs($emp)->post(route('deals.store'), ['name' => 'Тендер', 'client_name' => 'Иван', 'company_name' => 'ТОО Тендер', 'address' => 'Астана, пр. Мәңгілік Ел 1', 'budget' => 1000000])
            ->assertRedirect();
        $this->assertEquals(1, Deal::count());
    }

    public function test_advance_moves_to_next_stage(): void
    {
        $this->seedAll();
        $admin = $this->user('admin');
        $first = DealStage::orderBy('order')->first();
        $second = DealStage::orderBy('order')->skip(1)->first();
        $deal = Deal::create(['number' => 'BAIA-W-1', 'name' => 'D', 'budget' => 1, 'status' => 'active', 'deal_stage_id' => $first->id]);

        $this->actingAs($admin)->patch(route('deals.advance', $deal))->assertRedirect();
        $this->assertEquals($second->id, $deal->fresh()->deal_stage_id);
    }

    public function test_send_to_workshop_creates_project_at_first_cex_stage(): void
    {
        $this->seedAll();
        $admin = $this->user('admin');
        $deal = Deal::create(['number' => 'BAIA-W-2', 'name' => 'Заказ', 'budget' => 1000000, 'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->first()->id]);

        $this->actingAs($admin)->post(route('deals.toWorkshop', $deal))->assertRedirect();

        $project = Project::first();
        $this->assertNotNull($project);
        $firstCex = ProjectStage::orderBy('order')->first();
        $this->assertEquals('Кесу', $firstCex->name);
        $this->assertEquals($firstCex->id, $project->project_stage_id);
        $this->assertEquals('closed', $deal->fresh()->status);
    }

    public function test_admin_can_manage_stages(): void
    {
        $this->seedAll();
        $admin = $this->user('admin');

        $this->actingAs($admin)->post(route('stages.store'), ['kind' => 'project', 'name' => 'Покраска', 'color' => '#123456'])
            ->assertRedirect();
        $stage = ProjectStage::where('name', 'Покраска')->first();
        $this->assertNotNull($stage);

        $this->actingAs($admin)->put(route('stages.update', ['project', $stage->id]), ['name' => 'Лакировка'])->assertRedirect();
        $this->assertEquals('Лакировка', $stage->fresh()->name);

        $this->actingAs($admin)->delete(route('stages.destroy', ['project', $stage->id]))->assertRedirect();
        $this->assertNull(ProjectStage::find($stage->id));
    }

    public function test_stage_management_forbidden_for_employee(): void
    {
        $this->seedAll();
        $emp = $this->user('employee');
        $this->actingAs($emp)->get(route('stages.index'))->assertForbidden();
    }

    public function test_admin_marks_workshop_stage_as_completing_and_it_is_unique(): void
    {
        // Админ сам назначает завершающий этап цеха; завершающий один на воронку.
        $this->seedAll();
        $admin = $this->user('admin');
        $a = ProjectStage::create(['name' => 'Отправка', 'order' => 1, 'type' => 'project', 'is_active' => true, 'checklist' => [], 'is_completed' => true]);
        $b = ProjectStage::create(['name' => 'Приёмка', 'order' => 2, 'type' => 'project', 'is_active' => true, 'checklist' => []]);

        // Назначаем завершающим B — с A флаг снимается (один на воронку).
        $this->actingAs($admin)->put(route('stages.update', ['project', $b->id]), ['is_completed' => true])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue((bool) $b->fresh()->is_completed);
        $this->assertFalse((bool) $a->fresh()->is_completed);

        // Снять флаг тоже можно.
        $this->actingAs($admin)->put(route('stages.update', ['project', $b->id]), ['is_completed' => false])->assertRedirect();
        $this->assertFalse((bool) $b->fresh()->is_completed);
    }

    public function test_each_company_has_own_workshop_funnel(): void
    {
        // Цех раздельный: BAIA — мебельный, ASU — швейный. Заказ попадает
        // в цех СВОЕЙ компании, этап чужого цеха недоступен.
        $this->seedAll();
        $admin = $this->user('admin');
        $baia = \App\Models\Company::where('code', 'BAIA')->firstOrFail();
        $asu = \App\Models\Company::where('code', 'ASU')->firstOrFail();
        $admin->companies()->attach([$baia->id, $asu->id]);

        $baiaCut = ProjectStage::create(['company_id' => $baia->id, 'name' => 'Кесу (мебель)', 'order' => 100, 'type' => 'project', 'is_active' => true, 'checklist' => []]);
        $asuSew = ProjectStage::create(['company_id' => $asu->id, 'name' => 'Тігу (швейный)', 'order' => 100, 'type' => 'project', 'is_active' => true, 'checklist' => []]);

        $deal = Deal::create([
            'company_id' => $asu->id, 'number' => 'ASU-W-1', 'name' => 'Швейный заказ', 'budget' => 100000,
            'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->first()->id,
        ]);
        $this->actingAs($admin)->post(route('deals.toWorkshop', $deal))->assertRedirect();

        $project = Project::where('deal_id', $deal->id)->firstOrFail();
        // Первый этап воронки ASU (сидерные общие этапы + свой) — НЕ этап BAIA.
        $this->assertNotEquals($baiaCut->id, $project->project_stage_id);

        // Перенос заказа ASU на этап цеха BAIA запрещён.
        $this->actingAs($admin)->patch(route('projects.stage', $project), ['project_stage_id' => $baiaCut->id]);
        $this->assertNotEquals($baiaCut->id, $project->fresh()->project_stage_id);

        // На свой швейный этап — можно.
        $this->actingAs($admin)->patch(route('projects.stage', $project), ['project_stage_id' => $asuSew->id]);
        $this->assertEquals($asuSew->id, $project->fresh()->project_stage_id);
    }
}
