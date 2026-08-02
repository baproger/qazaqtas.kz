<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Document;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnershipScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
    }

    private function manager(): User
    {
        $u = User::factory()->create();
        $u->assignRole('manager');

        return $u;
    }

    private function deal(User $owner): Deal
    {
        return Deal::create(['number' => 'D-'.uniqid(), 'name' => 'X', 'company_name' => 'ТОО', 'client_name' => 'И', 'budget' => 100, 'status' => 'active', 'deal_stage_id' => DealStage::orderBy('order')->first()->id, 'responsible_user_id' => $owner->id]);
    }

    // ---- Документы ----

    public function test_manager_cannot_download_foreign_document(): void
    {
        $mgr = $this->manager();
        $deal = $this->deal($this->manager());
        $doc = Document::create(['documentable_type' => 'deal', 'documentable_id' => $deal->id, 'name' => 'секрет.pdf', 'file_path' => 'documents/x', 'version' => 1, 'user_id' => $deal->responsible_user_id, 'is_active' => true]);

        $this->actingAs($mgr)->get(route('documents.download', $doc->id))->assertForbidden();
    }

    public function test_manager_cannot_delete_foreign_document(): void
    {
        $mgr = $this->manager();
        $deal = $this->deal($this->manager());
        $doc = Document::create(['documentable_type' => 'deal', 'documentable_id' => $deal->id, 'name' => 'd.pdf', 'file_path' => 'documents/x', 'version' => 1, 'user_id' => $deal->responsible_user_id, 'is_active' => true]);

        $this->actingAs($mgr)->delete(route('documents.destroy', $doc->id))->assertForbidden();
        $this->assertNotNull($doc->fresh());
    }

    // ---- Задачи ----

    public function test_manager_cannot_create_task_on_foreign_deal(): void
    {
        $mgr = $this->manager();
        $deal = $this->deal($this->manager());

        $this->actingAs($mgr)->post(route('tasks.store'), ['title' => 'T', 'taskable_type' => 'deal', 'taskable_id' => $deal->id])->assertForbidden();
    }

    public function test_manager_cannot_change_status_of_foreign_task(): void
    {
        $mgr = $this->manager();
        $other = $this->manager();
        $deal = $this->deal($other);
        $task = Task::create(['title' => 'T', 'taskable_type' => 'deal', 'taskable_id' => $deal->id, 'creator_id' => $other->id, 'assignee_id' => $other->id, 'status' => 'new', 'priority' => 'medium']);

        $this->actingAs($mgr)->patch(route('tasks.status', $task->id), ['status' => 'done'])->assertForbidden();
    }

    public function test_manager_can_create_task_on_own_deal(): void
    {
        $mgr = $this->manager();
        $deal = $this->deal($mgr);

        $this->actingAs($mgr)->post(route('tasks.store'), ['title' => 'T', 'taskable_type' => 'deal', 'taskable_id' => $deal->id])->assertRedirect();
    }

    // ---- Mass assignment этапа ----

    public function test_deal_update_cannot_change_stage_or_status(): void
    {
        $mgr = $this->manager();
        $deal = $this->deal($mgr);
        $wonStage = DealStage::where('is_won', true)->first();

        $this->actingAs($mgr)->put(route('deals.update', $deal->id), [
            'name' => 'X', 'client_name' => 'И', 'company_name' => 'ТОО', 'address' => 'адрес', 'budget' => 100,
            'deal_stage_id' => $wonStage->id, 'status' => 'closed',
        ])->assertRedirect();

        $deal->refresh();
        $this->assertNotEquals($wonStage->id, $deal->deal_stage_id);
        $this->assertNotEquals('closed', $deal->status);
    }

    public function test_manager_creating_deal_is_forced_as_responsible(): void
    {
        $mgr = $this->manager();
        $other = $this->manager();

        $this->actingAs($mgr)->post(route('deals.store'), [
            'name' => 'X', 'client_name' => 'И', 'company_name' => 'ТОО', 'address' => 'адрес', 'budget' => 100,
            'responsible_user_id' => $other->id, // попытка назначить другого
        ])->assertRedirect();

        $this->assertSame($mgr->id, Deal::latest('id')->first()->responsible_user_id);
    }

    // ---- Утечка сумм цеху ----

    public function test_workshop_staff_does_not_receive_project_budget(): void
    {
        $emp = User::factory()->create();
        $emp->assignRole('employee');
        $deal = $this->deal($this->manager());
        $project = Project::create(['number' => 'P-1', 'name' => 'Заказ', 'deal_id' => $deal->id, 'budget' => 999000, 'status' => 'active', 'project_stage_id' => \App\Models\ProjectStage::orderBy('order')->first()->id, 'responsible_user_id' => $deal->responsible_user_id]);

        $this->actingAs($emp)->get(route('projects.show', $project->id))
            ->assertInertia(fn ($page) => $page->missing('project.budget'));
    }
}
