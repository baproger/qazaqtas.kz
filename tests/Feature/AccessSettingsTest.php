<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessScope;
use App\Support\RoleTraits;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Настройки → Доступы: редактор прав.
 *
 * Самая опасная страница системы — она раздаёт доступ ко всему остальному.
 * Тесты стерегут три границы: кто её открывает, что нельзя тронуть и что
 * личное право не подменяет собой роль.
 */
class AccessSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->director = User::factory()->create();
        $this->director->assignRole('director');
    }

    public function test_only_admin_opens_the_editor(): void
    {
        $this->actingAs($this->admin)->get(route('access.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Settings/Access')->has('roles')->has('modules'));

        // Директор видит всё как наблюдатель — но не раздаёт права: иначе за
        // минуту выпишет себе доступ ко всему.
        $this->actingAs($this->director)->get(route('access.index'))->assertForbidden();
    }

    public function test_only_admin_changes_a_role(): void
    {
        $this->actingAs($this->director)
            ->put(route('access.update'), ['role' => 'manager', 'scopes' => []])
            ->assertForbidden();

        $this->assertTrue(Role::findByName('manager')->permissions->isNotEmpty());
    }

    /** Роль admin — суперпользователь через Gate::before: править её нечего. */
    public function test_the_admin_role_cannot_be_stripped(): void
    {
        $this->actingAs($this->admin)
            ->put(route('access.update'), ['role' => 'admin', 'scopes' => []])
            ->assertSessionHas('error');

        $this->assertTrue(Role::findByName('admin')->permissions->isNotEmpty());
    }

    /**
     * Область и право сохраняются вместе: «Нет доступа» снимает право, любая
     * другая область его выдаёт. Разведи эти два действия — и однажды право
     * окажется выдано без области или наоборот.
     */
    public function test_admin_grants_a_permission_with_a_scope(): void
    {
        $before = Role::findByName('supplier')->permissions->pluck('name')->all();
        $this->assertNotContains('report.viewAny', $before);

        $scopes = array_fill_keys($before, AccessScope::OWN);
        $scopes['report.viewAny'] = AccessScope::DEPARTMENT_TREE;

        $this->actingAs($this->admin)->put(route('access.update'), [
            'role' => 'supplier', 'scopes' => $scopes,
        ])->assertSessionHas('success');

        $supplier = Role::findByName('supplier')->fresh();
        $this->assertContains('report.viewAny', $supplier->permissions->pluck('name')->all());
        $this->assertDatabaseHas('role_module_access', [
            'role_id' => $supplier->id, 'permission' => 'report.viewAny', 'scope' => AccessScope::DEPARTMENT_TREE,
        ]);
    }

    /** «Нет доступа» — это снятое право, а не право с пустой областью. */
    public function test_the_none_scope_revokes_the_permission(): void
    {
        $this->actingAs($this->admin)->put(route('access.update'), [
            'role' => 'supplier',
            'scopes' => ['project.viewAny' => AccessScope::NONE],
        ])->assertSessionHas('success');

        $supplier = Role::findByName('supplier')->fresh();
        $this->assertNotContains('project.viewAny', $supplier->permissions->pluck('name')->all());
        $this->assertDatabaseMissing('role_module_access', [
            'role_id' => $supplier->id, 'permission' => 'project.viewAny',
        ]);
    }

    /** Новая роль: код латиницей, копия образца берёт и права, и признаки. */
    public function test_admin_creates_a_role_as_a_copy(): void
    {
        $this->actingAs($this->admin)->post(route('access.roles.store'), [
            'label' => 'Руководитель отдела продаж',
            'name' => 'sales_head',
            'copy_from' => 'manager',
        ])->assertSessionHas('success');

        $role = Role::findByName('sales_head');
        $manager = Role::findByName('manager');

        $this->assertSame('Руководитель отдела продаж', $role->label);
        $this->assertFalse($role->is_system, 'Созданная роль не системная — её можно удалить.');
        $this->assertSame($manager->sees_money, $role->sees_money);
        $this->assertSame(
            $manager->permissions->pluck('name')->sort()->values()->all(),
            $role->permissions->pluck('name')->sort()->values()->all(),
        );
    }

    /** Код роли — латиница: на нём держатся политики и запасные проверки. */
    public function test_a_role_code_must_be_a_latin_slug(): void
    {
        $this->actingAs($this->admin)->post(route('access.roles.store'), [
            'label' => 'Кладовщик', 'name' => 'Кладовщик',
        ])->assertSessionHasErrors('name');
    }

    /**
     * Админ удаляет ЛЮБУЮ роль, включая системную: это его штатное расписание.
     *
     * Завёл заново с тем же кодом — политики, которые на код смотрят, снова
     * заработали. Уходят вместе с ролью только её области.
     */
    public function test_admin_deletes_even_a_system_role(): void
    {
        $financist = Role::findByName('financist');

        $this->actingAs($this->admin)
            ->delete(route('access.roles.destroy', $financist->id))
            ->assertSessionHas('success');

        $this->assertNull(Role::where('name', 'financist')->first());
        $this->assertDatabaseMissing('role_module_access', ['role_id' => $financist->id]);
    }

    /**
     * Кроме «СЕО» — и не по вкусу: это суперпользователь через Gate::before.
     * Удали его, и в систему не войдёт больше никто, включая того, кто удалял.
     */
    public function test_the_admin_role_can_never_be_deleted(): void
    {
        $this->actingAs($this->admin)
            ->delete(route('access.roles.destroy', Role::findByName('admin')->id))
            ->assertSessionHas('error');

        $this->assertNotNull(Role::findByName('admin'));
    }

    /**
     * Роль с людьми удаляется, но ответ ГОВОРИТ, скольких оставили без роли.
     *
     * Молчаливое удаление означало бы, что шесть человек потеряли доступ, а
     * владелец узнал бы об этом от них самих.
     */
    public function test_deleting_a_role_reports_how_many_were_left_without_one(): void
    {
        $this->actingAs($this->admin)->post(route('access.roles.store'), [
            'label' => 'Кладовщик', 'name' => 'storekeeper',
        ]);
        $role = Role::findByName('storekeeper');

        $person = User::factory()->create();
        $person->assignRole('storekeeper');

        $this->actingAs($this->admin)->delete(route('access.roles.destroy', $role->id))
            ->assertSessionHas('success', fn ($message) => str_contains($message, '1'));

        $this->assertNull(Role::where('name', 'storekeeper')->first());
        $this->assertTrue($person->fresh()->roles->isEmpty());
    }

    /**
     * Личное право — ДОБАВКА к роли, а не её копия.
     *
     * Продублируй здесь право роли — и снятие его у роли тихо оставило бы
     * доступ этому человеку персональной копией.
     */
    public function test_a_personal_permission_never_duplicates_the_role(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->actingAs($this->admin)->put(route('access.updateUser', $manager->id), [
            'permissions' => ['deal.viewAny', 'report.viewAny'],
        ])->assertSessionHas('success');

        $direct = $manager->fresh()->getDirectPermissions()->pluck('name')->all();

        $this->assertSame(['report.viewAny'], $direct, 'Право роли личной копией не становится.');
        $this->assertTrue($manager->fresh()->can('report.viewAny'));
    }

    public function test_a_personal_permission_is_admin_only(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->actingAs($this->director)
            ->put(route('access.updateUser', $manager->id), ['permissions' => ['report.viewAny']])
            ->assertForbidden();

        $this->assertFalse($manager->fresh()->can('report.viewAny'));
    }

    /** У админа и так всё: личные права ему не выдают. */
    public function test_the_admin_gets_no_personal_permissions(): void
    {
        $other = User::factory()->create();
        $other->assignRole('admin');

        $this->actingAs($this->admin)
            ->put(route('access.updateUser', $other->id), ['permissions' => ['report.viewAny']])
            ->assertStatus(422);
    }

    /** Личные доступы едут в карточку только админу и только для не-админа. */
    public function test_the_profile_ships_access_only_to_admin(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('manager');

        $this->actingAs($this->admin)->get(route('users.show', $manager->id))
            ->assertInertia(fn (Assert $p) => $p->has('access.modules')->has('access.fromRole')->etc());

        $this->actingAs($this->director)->get(route('users.show', $manager->id))
            ->assertInertia(fn (Assert $p) => $p->where('access', null)->etc());

        $this->actingAs($this->admin)->get(route('users.show', $this->admin->id))
            ->assertInertia(fn (Assert $p) => $p->where('access', null)->etc());
    }

    /** Людей добавляют в роль прямо из колонки матрицы. */
    public function test_admin_adds_people_to_a_role_from_the_matrix(): void
    {
        $person = User::factory()->create();
        $person->assignRole('employee');

        $manager = Role::findByName('manager');

        $this->actingAs($this->admin)
            ->post(route('access.roles.addUsers', $manager->id), ['users' => [$person->id]])
            ->assertSessionHas('success');

        $fresh = $person->fresh();
        $this->assertTrue($fresh->hasRole('manager'));
        // Роль у человека ОДНА: два набора прав на одного — вопрос «по какому
        // из них его судить», на который нет ответа.
        $this->assertFalse($fresh->hasRole('employee'));
    }

    /** Роль «СЕО» из матрицы не раздают: у неё своя защита в карточке. */
    public function test_the_admin_role_is_not_handed_out_from_the_matrix(): void
    {
        $person = User::factory()->create();
        $person->assignRole('manager');

        $this->actingAs($this->admin)
            ->post(route('access.roles.addUsers', Role::findByName('admin')->id), ['users' => [$person->id]])
            ->assertStatus(422);

        $this->assertFalse($person->fresh()->hasRole('admin'));
    }

    /** Чип × снимает роль с человека; директор так не может. */
    public function test_removing_a_person_from_a_role_is_admin_only(): void
    {
        $person = User::factory()->create();
        $person->assignRole('manager');
        $manager = Role::findByName('manager');

        $this->actingAs($this->director)
            ->delete(route('access.roles.removeUser', [$manager->id, $person->id]))
            ->assertForbidden();
        $this->assertTrue($person->fresh()->hasRole('manager'));

        $this->actingAs($this->admin)
            ->delete(route('access.roles.removeUser', [$manager->id, $person->id]))
            ->assertSessionHas('success');
        $this->assertFalse($person->fresh()->hasRole('manager'));
    }

    /** «Закрыть доступ ко всем»: одним действием снимаются все права роли. */
    public function test_closing_all_access_strips_the_role(): void
    {
        $supplier = Role::findByName('supplier');
        $this->assertTrue($supplier->permissions->isNotEmpty());

        $scopes = array_fill_keys(
            Permission::pluck('name')->all(),
            AccessScope::NONE,
        );

        $this->actingAs($this->admin)
            ->put(route('access.update'), ['role' => 'supplier', 'scopes' => $scopes])
            ->assertSessionHas('success');

        $this->assertTrue($supplier->fresh()->permissions->isEmpty());
        $this->assertDatabaseMissing('role_module_access', ['role_id' => $supplier->id]);
    }

    /** «Открыть доступ ко всем»: роль получает область «Все» во всех разделах. */
    public function test_opening_all_access_gives_every_permission(): void
    {
        $all = Permission::pluck('name')->all();

        $this->actingAs($this->admin)->put(route('access.update'), [
            'role' => 'supplier',
            'scopes' => array_fill_keys($all, AccessScope::ALL),
        ])->assertSessionHas('success');

        $supplier = Role::findByName('supplier')->fresh();
        $this->assertSame(count($all), $supplier->permissions->count());
        $this->assertSame(AccessScope::ALL, AccessScope::for(
            tap(User::factory()->create(), fn ($u) => $u->assignRole('supplier')),
            'deal.viewAny',
        ));
    }

    /** Переименование меняет подпись, но НЕ код: на коде держатся политики. */
    public function test_renaming_keeps_the_role_code(): void
    {
        $role = Role::findByName('supplier');

        $this->actingAs($this->admin)
            ->put(route('access.roles.rename', $role->id), ['label' => 'Закупщик'])
            ->assertSessionHas('success');

        $fresh = $role->fresh();
        $this->assertSame('Закупщик', $fresh->label);
        $this->assertSame('supplier', $fresh->name, 'Код роли неизменен.');
    }

    /**
     * Удалённая роль не роняет систему.
     *
     * Роли удаляет владелец, а код спрашивает их по имени: кому уходит весть
     * о нехватке склада, кто закрывает гейт-этап. `User::role('director')`
     * бросает исключение, если роли нет, — и создание сделки отвечало
     * пятисоткой. Спрашивать роль по имени можно, рассчитывать на её
     * существование нельзя.
     */
    public function test_a_deleted_role_does_not_break_the_system(): void
    {
        $this->seed(StageSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $company = Company::where('code', 'QT')->value('id');
        $manager->companies()->attach($company);

        // Владелец удалил «Директора» — а на его имя ссылается рассылка
        // о нехватке склада при создании сделки.
        $this->actingAs($this->admin)
            ->delete(route('access.roles.destroy', Role::findByName('director')->id))
            ->assertSessionHas('success');

        $product = Product::create([
            'name' => 'Плитка', 'unit' => 'м²', 'price' => 12000, 'is_active' => true,
        ]);

        $this->actingAs($manager)->post(route('deals.store'), [
            'company_name' => 'ТОО Тест',
            'address' => 'Алматы',
            'budget' => 120000,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'price' => 12000]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Deal::count(), 'Сделка создалась, несмотря на удалённую роль.');
    }

    /** Пустой список ролей — пустая рассылка, а не «всем подряд». */
    public function test_missing_roles_notify_nobody_rather_than_everybody(): void
    {
        User::factory()->count(3)->create();

        $this->assertSame(0, RoleTraits::users(['no_such_role'])->count());
    }

    /** Сделку заводят админ, менеджер, директор и ассистент. */
    public function test_four_roles_can_create_a_deal(): void
    {
        foreach (['admin', 'manager', 'director', 'assistant'] as $name) {
            $this->assertTrue(
                Role::findByName($name)->hasPermissionTo('deal.create'),
                "Роль «{$name}» обязана заводить сделки.",
            );
        }
    }

    /**
     * Человек без роли не видит денег.
     *
     * Роли удаляет владелец, значит люди без роли появляются на законных
     * основаниях. Правило «нет запрещающей роли — значит можно» тогда
     * открывало бы суммы само собой. Доступ дают, а не забывают отнять.
     */
    public function test_a_person_without_a_role_sees_no_money(): void
    {
        $orphan = User::factory()->create();

        $this->assertFalse(RoleTraits::seesMoney($orphan));
        $this->assertFalse(RoleTraits::isLeadership($orphan));
        $this->assertSame(
            AccessScope::NONE,
            AccessScope::for($orphan, 'deal.viewAny'),
        );
    }

    /**
     * Роль, созданная владельцем, показывается ПОДПИСЬЮ всюду.
     *
     * Подписи жили зашитым словарём в четырёх шаблонах сразу, и новая роль
     * выпадала голым кодом («foreman» вместо «Бригадир»). Теперь список один
     * и приходит из БД общими данными Inertia — значит и новая роль в нём есть.
     */
    public function test_a_new_role_shows_its_label_everywhere(): void
    {
        $this->actingAs($this->admin)->post(route('access.roles.store'), [
            'label' => 'Кладовщик', 'name' => 'storekeeper',
        ])->assertSessionHas('success');

        $this->actingAs($this->admin)->get(route('users.index'))
            ->assertInertia(fn (Assert $p) => $p
                ->where('roleLabels.storekeeper', 'Кладовщик')
                // И системные, которых в зашитом словаре не было.
                ->where('roleLabels.foreman', 'Бригадир')
                ->where('roleLabels.production_head', 'Начальник производства')
                ->where('roleLabels.assistant', 'Ассистент')
                ->etc());
    }

    /** Переименовали роль — новая подпись видна сразу, без правки шаблонов. */
    public function test_renaming_a_role_changes_its_label_everywhere(): void
    {
        $this->actingAs($this->admin)
            ->put(route('access.roles.rename', Role::findByName('foreman')->id), ['label' => 'Старший бригады'])
            ->assertSessionHas('success');

        $this->actingAs($this->admin)->get(route('users.index'))
            ->assertInertia(fn (Assert $p) => $p->where('roleLabels.foreman', 'Старший бригады')->etc());
    }

    /**
     * После создания роли страница остаётся рабочей.
     *
     * Была белая: черновик галочек строился один раз при создании компонента,
     * а список ролей приходил новый — шаблон брал `traits[r.name]` у роли,
     * которой в черновике ещё не было. Сервер обязан отдавать КАЖДУЮ роль
     * полным набором ключей, на которые рассчитывает страница.
     */
    public function test_a_new_role_arrives_with_a_complete_payload(): void
    {
        $this->actingAs($this->admin)->post(route('access.roles.store'), [
            'label' => 'РОП', 'name' => 'rop', 'copy_from' => 'manager',
        ])->assertSessionHas('success');

        $this->actingAs($this->admin)->get(route('access.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                $roles = collect($page->toArray()['props']['roles']);
                $new = $roles->firstWhere('name', 'rop');

                $this->assertNotNull($new, 'Новая роль обязана быть в списке.');

                // Ключи, по которым страница индексирует состояние: нет любого —
                // и шаблон падает на undefined, а пользователь видит белый экран.
                foreach (['id', 'name', 'label', 'locked', 'system', 'traits', 'scopes', 'holders'] as $key) {
                    $this->assertArrayHasKey($key, $new, "Роль без ключа «{$key}».");
                }
                foreach (['is_leadership', 'sees_money', 'is_workshop'] as $trait) {
                    $this->assertArrayHasKey($trait, $new['traits'], "Признак «{$trait}» не пришёл.");
                }

                // И у ВСЕХ ролей набор одинаковый — иначе падение просто
                // переедет на другую строку.
                foreach ($roles as $role) {
                    $this->assertArrayHasKey('traits', $role, "Роль «{$role['name']}» без признаков.");
                    $this->assertArrayHasKey('scopes', $role, "Роль «{$role['name']}» без областей.");
                }
            });
    }
}
