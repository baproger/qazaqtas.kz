<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Структура компании: дерево отделов.
 *
 * Это граница областей доступа (§3): «Отдел» и «Отдел и подчинённые» без
 * дерева не значат ничего. Поэтому кривое дерево — не косметика: цикл в нём
 * подвесил бы обход, а каскадное удаление унесло бы половину структуры.
 */
class CompanyStructureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_the_page_shows_the_tree_and_the_unassigned(): void
    {
        $sales = Department::create(['name' => 'Продажи', 'is_active' => true]);
        Department::create(['name' => 'Офлайн', 'parent_id' => $sales->id, 'is_active' => true]);

        $inSales = User::factory()->create(['department_id' => $sales->id]);
        $inSales->assignRole('manager');
        $loose = User::factory()->create(['department_id' => null]);
        $loose->assignRole('manager');

        $this->actingAs($this->admin)->get(route('structure.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('Settings/Structure')
                ->has('departments', 2)
                ->where('unassigned', fn ($rows) => collect($rows)->pluck('id')->contains($loose->id))
                ->etc());
    }

    /** Сотрудник цеха структуру компании не открывает: это не его дело. */
    public function test_the_page_needs_the_department_permission(): void
    {
        $worker = User::factory()->create();
        $worker->assignRole('employee');

        $this->actingAs($worker)->get(route('structure.index'))->assertForbidden();
    }

    /**
     * Отдел нельзя подчинить самому себе или своему потомку.
     *
     * Такая ссылка рвёт дерево: обход subtreeIds пошёл бы по кругу, и страница
     * повисла бы на одной кривой записи.
     */
    public function test_a_department_cannot_become_its_own_child(): void
    {
        $parent = Department::create(['name' => 'Продажи', 'is_active' => true]);
        $child = Department::create(['name' => 'Офлайн', 'parent_id' => $parent->id, 'is_active' => true]);

        $this->actingAs($this->admin)->put(route('structure.update', $parent->id), [
            'name' => 'Продажи', 'parent_id' => $child->id,
        ])->assertSessionHas('error');

        $this->assertNull($parent->fresh()->parent_id);
    }

    /**
     * Удаление отдела поднимает подчинённые на уровень выше, а не удаляет
     * следом: каскад унёс бы половину структуры одним кликом.
     */
    public function test_deleting_a_department_lifts_its_children(): void
    {
        $root = Department::create(['name' => 'Компания', 'is_active' => true]);
        $mid = Department::create(['name' => 'Продажи', 'parent_id' => $root->id, 'is_active' => true]);
        $leaf = Department::create(['name' => 'Офлайн', 'parent_id' => $mid->id, 'is_active' => true]);

        $person = User::factory()->create(['department_id' => $mid->id]);
        $person->assignRole('manager');

        $this->actingAs($this->admin)->delete(route('structure.destroy', $mid->id))
            ->assertSessionHas('success');

        $this->assertSame($root->id, $leaf->fresh()->parent_id, 'Подотдел поднялся, а не исчез.');
        $this->assertNull($person->fresh()->department_id);
    }

    /** Поддерево: отдел, его дети и дети детей — вглубь. */
    public function test_the_subtree_reaches_every_level(): void
    {
        $root = Department::create(['name' => 'Компания', 'is_active' => true]);
        $mid = Department::create(['name' => 'Продажи', 'parent_id' => $root->id, 'is_active' => true]);
        $leaf = Department::create(['name' => 'Офлайн', 'parent_id' => $mid->id, 'is_active' => true]);
        $other = Department::create(['name' => 'Цех', 'is_active' => true]);

        $ids = $root->subtreeIds();

        $this->assertEqualsCanonicalizing([$root->id, $mid->id, $leaf->id], $ids);
        $this->assertNotContains($other->id, $ids);
    }

    public function test_a_person_is_moved_between_departments(): void
    {
        $sales = Department::create(['name' => 'Продажи', 'is_active' => true]);
        $person = User::factory()->create(['department_id' => null]);
        $person->assignRole('manager');

        $this->actingAs($this->admin)
            ->put(route('structure.assign', $person->id), ['department_id' => $sales->id])
            ->assertSessionHas('success');

        $this->assertSame($sales->id, $person->fresh()->department_id);
    }

    /** Менеджер структуру не правит: отделы ведёт руководство. */
    public function test_a_manager_cannot_edit_the_structure(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->actingAs($manager)->post(route('structure.store'), ['name' => 'Свой отдел'])
            ->assertForbidden();

        $this->assertSame(0, Department::count());
    }
}
