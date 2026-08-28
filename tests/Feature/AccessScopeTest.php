<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessScope;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Область доступа: не «пустят ли», а «на сколько записей».
 *
 * Между «видит свои» и «видит всё» раньше не было места, и руководителю
 * отдела приходилось выдавать роль директора — вместе с чужими деньгами.
 * Границу задаёт дерево отделов, поэтому две страницы настроек связаны.
 */
class AccessScopeTest extends TestCase
{
    use RefreshDatabase;

    private Department $sales;

    private Department $offline;

    private User $head;

    private User $peer;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(StageSeeder::class);
        AccessScope::flush();

        // Продажи → Офлайн-продажи: подчинённый отдел нужен, чтобы отличить
        // «Отдел» от «Отдел и подчинённые».
        $this->sales = Department::create(['name' => 'Отдел продаж', 'is_active' => true]);
        $this->offline = Department::create(['name' => 'Офлайн продаж', 'parent_id' => $this->sales->id, 'is_active' => true]);
        $other = Department::create(['name' => 'Производство', 'is_active' => true]);

        $this->head = $this->manager('Руководитель продаж', $this->sales->id);
        $this->peer = $this->manager('Менеджер офлайна', $this->offline->id);
        $this->stranger = $this->manager('Менеджер производства', $other->id);

        $company = Company::where('code', 'QT')->value('id');
        $stage = DealStage::orderBy('order')->value('id');

        foreach ([$this->head, $this->peer, $this->stranger] as $i => $user) {
            Deal::create([
                'company_id' => $company, 'number' => 'S-'.$i, 'name' => 'X',
                'company_name' => 'Клиент '.$i, 'client_name' => 'Товар', 'budget' => 100000,
                'status' => 'active', 'deal_stage_id' => $stage, 'responsible_user_id' => $user->id,
            ]);
        }
    }

    private function manager(string $name, int $departmentId): User
    {
        $user = User::factory()->create(['name' => $name, 'department_id' => $departmentId]);
        $user->assignRole('manager');

        return $user;
    }

    private function setScope(string $role, string $permission, string $scope): void
    {
        DB::table('role_module_access')->updateOrInsert(
            ['role_id' => Role::findByName($role)->id, 'permission' => $permission],
            ['scope' => $scope, 'created_at' => now(), 'updated_at' => now()],
        );
        AccessScope::flush();
    }

    /** @return array<int, string> названия компаний в списке сделок */
    private function visibleTo(User $user): array
    {
        $names = [];
        $this->actingAs($user)->get(route('deals.index', ['view' => 'list']))
            ->assertInertia(function (Assert $page) use (&$names) {
                $page->has('deals', fn (Assert $deals) => $deals->etc());
                $names = collect(data_get($page->toArray(), 'props.deals.data') ?? data_get($page->toArray(), 'props.deals'))
                    ->pluck('company_name')->sort()->values()->all();
            });

        return $names;
    }

    /** Без настройки всё как раньше: менеджер видит только свои сделки. */
    public function test_without_a_setting_a_manager_still_sees_only_his_own(): void
    {
        $this->assertSame(['Клиент 0'], $this->visibleTo($this->head));
    }

    /** «Отдел» — свой отдел, но НЕ подчинённый: иначе два уровня совпали бы. */
    public function test_department_scope_stops_at_its_own_department(): void
    {
        $this->setScope('manager', 'deal.viewAny', AccessScope::DEPARTMENT);

        $this->assertSame(['Клиент 0'], $this->visibleTo($this->head));
        $this->assertSame(['Клиент 1'], $this->visibleTo($this->peer));
    }

    /** «Отдел и подчинённые» — вниз по дереву, но не вбок. */
    public function test_department_tree_scope_reaches_children_but_not_siblings(): void
    {
        $this->setScope('manager', 'deal.viewAny', AccessScope::DEPARTMENT_TREE);

        // Руководитель продаж видит себя и офлайн-продажи, но не производство.
        $this->assertSame(['Клиент 0', 'Клиент 1'], $this->visibleTo($this->head));
        // Снизу вверх дерево не работает: подчинённый начальника не видит.
        $this->assertSame(['Клиент 1'], $this->visibleTo($this->peer));
        $this->assertSame(['Клиент 2'], $this->visibleTo($this->stranger));
    }

    public function test_all_scope_opens_the_whole_company(): void
    {
        $this->setScope('manager', 'deal.viewAny', AccessScope::ALL);

        $this->assertSame(['Клиент 0', 'Клиент 1', 'Клиент 2'], $this->visibleTo($this->head));
    }

    /**
     * Ошибка в настройке не должна ОТКРЫВАТЬ больше, чем открыто: «нет
     * доступа» отдаёт пустой список, а не всю компанию.
     */
    public function test_none_scope_shows_nothing(): void
    {
        $this->setScope('manager', 'deal.viewAny', AccessScope::NONE);

        $this->assertSame([], $this->visibleTo($this->head));
    }

    /**
     * Несколько ролей — берём САМУЮ ШИРОКУЮ область. Роль дают, чтобы что-то
     * открыть; пересечение закрывало бы только что разрешённое.
     */
    public function test_the_widest_scope_of_several_roles_wins(): void
    {
        $this->setScope('manager', 'deal.viewAny', AccessScope::OWN);
        $this->setScope('designer', 'deal.viewAny', AccessScope::ALL);

        $this->head->assignRole('designer');
        $this->head->unsetRelation('roles');

        $this->assertSame(AccessScope::ALL, AccessScope::for($this->head->fresh(), 'deal.viewAny'));
    }

    /** Человек без отдела видит хотя бы свои записи, а не пустоту. */
    public function test_a_person_without_a_department_still_sees_his_own(): void
    {
        $this->setScope('manager', 'deal.viewAny', AccessScope::DEPARTMENT);
        $this->head->update(['department_id' => null]);

        $this->assertSame(['Клиент 0'], $this->visibleTo($this->head->fresh()));
    }
}
