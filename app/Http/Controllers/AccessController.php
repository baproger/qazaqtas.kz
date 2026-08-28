<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

/**
 * Настройки → Доступы: кто что может, таблицей «модуль × роль».
 *
 * Раньше права жили только в сидере: чтобы дать снабженцу отчёты, нужен был
 * программист и деплой. Теперь это делает владелец.
 *
 * Три вещи, которые редактор НЕ отдаёт наружу:
 *
 * 1. Открыть его может только admin. Дай сюда director — и наблюдатель за
 *    минуту выпишет себе право на всё.
 * 2. Роль admin не правится. Она суперпользователь через `Gate::before`, и
 *    галочки у неё были бы ложью: снимешь — доступ всё равно останется.
 * 3. Критичные запреты остаются в политиках через `hasAnyRole` (§10.2).
 *    Права здесь ОТКРЫВАЮТ раздел, но не отменяют правил про деньги: снять
 *    галочку и выдать себе чужие суммы через админку нельзя.
 */
class AccessController extends Controller
{
    /** Роль-суперпользователь: её права не редактируются (Gate::before). */
    private const LOCKED_ROLE = 'admin';

    /**
     * Разделы системы человеческими словами. Ключ — префикс права
     * (`deal.viewAny` → `deal`), значение — как это называет владелец.
     *
     * Словарь здесь, а не в шаблоне: подписи меняются чаще кода (§10.5).
     */
    private const MODULES = [
        'deal' => ['Сделки', 'воронка продаж, карточка сделки'],
        'project' => ['Цех', 'доска заказов и карточки цеха'],
        'product' => ['Каталог', 'товары, категории, фото и 3D'],
        'client' => ['Клиенты', 'справочник заказчиков'],
        'department' => ['Отделы', 'структура компании'],
        'task' => ['Задачи', 'поручения и гейты этапов'],
        'invoice' => ['Счета', 'выставление счетов клиенту'],
        'payment' => ['Платежи', 'оплаты по счетам'],
        'expense' => ['Расходы', 'заявки, подтверждение, касса'],
        'document' => ['Документы', 'файлы и фото сделок и заказов'],
        'user' => ['Сотрудники', 'карточки, роли, деактивация'],
        'report' => ['Отчёты', 'сводный отчёт и аналитика'],
        'setting' => ['Настройки', 'этапы, экраны, сайт, доступы'],
        'role' => ['Роли', 'служебное — управление ролями'],
    ];

    /** Права вне схемы «модуль.действие»: у них своя подпись. */
    private const SINGLES = [
        'payroll.view' => ['Зарплата', 'своя ведомость и бонусы'],
    ];

    private const ABILITIES = [
        'viewAny' => 'Список',
        'view' => 'Карточка',
        'create' => 'Создание',
        'update' => 'Правка',
        'delete' => 'Удаление',
    ];

    /** Редактор прав — только админу: иначе роль выпишет права сама себе. */
    private function guard(Request $request): void
    {
        abort_unless($request->user()?->hasRole('admin'), 403);
    }

    public function index(Request $request): Response
    {
        $this->guard($request);

        $permissions = Permission::orderBy('name')->get(['id', 'name']);
        $granted = DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->get(['roles.name as role', 'permissions.name as permission'])
            ->groupBy('role')
            ->map(fn ($rows) => $rows->pluck('permission')->all());

        // Область каждой пары «роль × право». Нет записи — область считается
        // по признакам роли (AccessScope::for), и до первой настройки система
        // ведёт себя ровно как раньше.
        $scopes = DB::table('role_module_access')
            ->get(['role_id', 'permission', 'scope'])
            ->groupBy('role_id')
            ->map(fn ($rows) => $rows->pluck('scope', 'permission')->all());

        // Кто носит роль: колонка без лиц — просто слово, и владелец не видит,
        // кого именно он сейчас ограничивает.
        $holders = User::where('is_active', true)
            ->with('roles:id,name')
            ->get(['id', 'name', 'avatar'])
            ->flatMap(fn (User $u) => $u->roles->map(fn ($r) => [
                'role' => $r->name, 'id' => $u->id, 'name' => $u->name, 'avatar' => $u->avatar,
            ]))
            ->groupBy('role');

        return Inertia::render('Settings/Access', [
            'roles' => Role::orderBy('is_leadership', 'desc')->orderBy('name')->get()
                ->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'label' => $role->title(),
                    'locked' => $role->name === self::LOCKED_ROLE,
                    'system' => (bool) $role->is_system,
                    'traits' => [
                        'is_leadership' => (bool) $role->is_leadership,
                        'sees_money' => (bool) $role->sees_money,
                        'is_workshop' => (bool) $role->is_workshop,
                    ],
                    'permissions' => $granted[$role->name] ?? [],
                    'scopes' => $scopes[$role->id] ?? [],
                    'holders' => $holders->get($role->name, collect())->values(),
                ])->values(),
            'modules' => $this->modules($permissions->pluck('name')->all()),
            'abilities' => self::ABILITIES,
            // Уровни области: подписи и порядок задаёт AccessScope — второй
            // список рано или поздно разошёлся бы с расчётом.
            'scopeLevels' => collect(AccessScope::LEVELS)
                ->map(fn ($level) => ['value' => $level, 'label' => AccessScope::LABELS[$level]])
                ->values(),
            // Кого можно добавить в колонку роли: люди с их отделом и ролями,
            // плюс сами отделы и роли — чтобы добавлять группой, а не поштучно.
            'people' => User::where('is_active', true)
                ->with(['roles:id,name', 'department:id,name'])
                ->orderBy('name')
                ->get(['id', 'name', 'avatar', 'department_id'])
                ->map(fn (User $u) => [
                    'id' => $u->id, 'name' => $u->name, 'avatar' => $u->avatar,
                    'department_id' => $u->department_id,
                    'department' => $u->department?->name,
                    'roles' => $u->roles->pluck('name')->all(),
                ])->values(),
            'departments' => Department::where('is_active', true)
                ->orderBy('name')->get(['id', 'name']),
            'traitLabels' => [
                'is_leadership' => 'Руководство — видит всех',
                'sees_money' => 'Видит суммы',
                'is_workshop' => 'Цеховая роль',
            ],
        ]);
    }

    /**
     * Права в порядке разделов: сначала известные модули, затем всё, что
     * появилось позже и в словарь ещё не попало — терять их нельзя, иначе
     * новое право стало бы невидимым и неснимаемым.
     *
     * @param  array<int, string>  $names
     * @return array<int, array<string, mixed>>
     */
    private function modules(array $names): array
    {
        $byModule = collect($names)
            ->reject(fn ($name) => array_key_exists($name, self::SINGLES))
            ->groupBy(fn ($name) => explode('.', $name)[0]);

        $rows = [];
        foreach (self::MODULES as $key => [$label, $hint]) {
            if ($byModule->has($key)) {
                $rows[] = ['key' => $key, 'label' => $label, 'hint' => $hint,
                    'permissions' => $this->abilityMap($byModule->get($key))];
            }
        }

        foreach ($byModule->keys()->diff(array_keys(self::MODULES)) as $key) {
            $rows[] = ['key' => $key, 'label' => $key, 'hint' => '',
                'permissions' => $this->abilityMap($byModule->get($key))];
        }

        foreach (self::SINGLES as $name => [$label, $hint]) {
            if (in_array($name, $names, true)) {
                $rows[] = ['key' => $name, 'label' => $label, 'hint' => $hint,
                    'permissions' => ['view' => $name]];
            }
        }

        return $rows;
    }

    /**
     * Разделы для карточки сотрудника — тот же словарь, что в матрице ролей.
     * Второй список подписей рано или поздно разошёлся бы с первым.
     *
     * @return array<int, array<string, mixed>>
     */
    public function userModules(): array
    {
        return $this->modules(Permission::orderBy('name')->pluck('name')->all());
    }

    /** @return array<string, string> действие → подпись */
    public static function abilityLabels(): array
    {
        return self::ABILITIES;
    }

    /** @return array<string, string> действие → полное имя права */
    private function abilityMap(Collection $names): array
    {
        return $names->mapWithKeys(fn ($name) => [explode('.', $name)[1] ?? $name => $name])->all();
    }

    /** Как роль называется у владельца: в UI она не `financist`, а «Бухгалтер». */
    public static function roleLabel(string $role): string
    {
        return [
            'admin' => 'СЕО (админ)',
            'director' => 'Директор',
            'financist' => 'Финансист-Бухгалтер',
            'production_head' => 'Начальник производства',
            'assistant' => 'Ассистент',
            'manager' => 'Менеджер',
            'employee' => 'Сотрудник цеха',
            'foreman' => 'Бригадир',
            'designer' => 'Технолог',
            'supplier' => 'Снабженец',
            'lawyer' => 'Юрист',
            'cook' => 'Повар',
        ][$role] ?? $role;
    }

    /**
     * Переписать доступы роли целиком: пришёл полный набор пар «право → область».
     *
     * Право и область сохраняются вместе и означают разное: право отвечает
     * «пустят ли вообще» (его спрашивают политики), область — «на сколько
     * записей». «Нет доступа» снимает право; любая другая область его выдаёт.
     * Разведи эти два действия по разным кнопкам — и однажды право окажется
     * выдано без области или наоборот.
     */
    public function update(Request $request): RedirectResponse
    {
        $this->guard($request);

        $data = $request->validate([
            'role' => ['required', 'string', 'exists:roles,name'],
            'scopes' => ['present', 'array'],
            'scopes.*' => ['string', Rule::in(AccessScope::LEVELS)],
            'traits' => ['sometimes', 'array'],
            'traits.is_leadership' => ['sometimes', 'boolean'],
            'traits.sees_money' => ['sometimes', 'boolean'],
            'traits.is_workshop' => ['sometimes', 'boolean'],
        ]);

        if ($data['role'] === self::LOCKED_ROLE) {
            return back()->with('error', 'Права роли «СЕО» не меняются: она суперпользователь.');
        }

        $role = Role::where('name', $data['role'])->firstOrFail();
        $known = Permission::pluck('name')->flip();

        DB::transaction(function () use ($role, $data, $known) {
            $grant = [];
            $rows = [];

            foreach ($data['scopes'] as $permission => $scope) {
                // Право из формы, которого нет в системе, молча игнорируем:
                // подделанный запрос не должен создавать новых прав.
                if (! $known->has($permission)) {
                    continue;
                }
                if ($scope === AccessScope::NONE) {
                    continue;
                }
                $grant[] = $permission;
                $rows[] = [
                    'role_id' => $role->id, 'permission' => $permission, 'scope' => $scope,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }

            $role->syncPermissions($grant);

            DB::table('role_module_access')->where('role_id', $role->id)->delete();
            if ($rows !== []) {
                DB::table('role_module_access')->insert($rows);
            }

            if (isset($data['traits'])) {
                $role->update($data['traits']);
            }
        });

        AccessScope::flush();

        return back()->with('success', 'Доступы роли обновлены.');
    }

    /**
     * Новая роль.
     *
     * Имя — латиницей и кодом: на нём держатся политики и запасные проверки,
     * и переименование не должно их ронять (тот же принцип, что у
     * `stage_type`, §6). Владелец меняет `label`, код остаётся.
     *
     * Новая роль рождается ПУСТОЙ: ни прав, ни признаков. Дать ей их — второй
     * осознанный шаг, а не побочный эффект создания.
     */
    public function storeRole(Request $request): RedirectResponse
    {
        $this->guard($request);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:roles,name'],
            'copy_from' => ['nullable', 'string', 'exists:roles,name'],
        ], [], ['name' => 'код роли']);

        $role = Role::create([
            'name' => $data['name'],
            'label' => $data['label'],
            'guard_name' => 'web',
            'is_system' => false,
        ]);

        // Копия образца: с нуля набирать семьдесят галочек никто не станет,
        // и роль осталась бы пустой. Признаки копируем вместе с правами —
        // иначе копия «Менеджера» не видела бы сумм.
        if (! empty($data['copy_from'])) {
            $source = Role::where('name', $data['copy_from'])->firstOrFail();
            $role->syncPermissions($source->permissions);
            $role->update([
                'is_leadership' => $source->is_leadership,
                'sees_money' => $source->sees_money,
                'is_workshop' => $source->is_workshop,
            ]);

            $copied = DB::table('role_module_access')->where('role_id', $source->id)
                ->get(['permission', 'scope'])
                ->map(fn ($row) => [
                    'role_id' => $role->id, 'permission' => $row->permission, 'scope' => $row->scope,
                    'created_at' => now(), 'updated_at' => now(),
                ])->all();

            if ($copied !== []) {
                DB::table('role_module_access')->insert($copied);
            }
        }

        AccessScope::flush();

        return back()->with('success', 'Роль создана.');
    }

    /**
     * Добавить людей в роль прямо из колонки матрицы.
     *
     * Роль у человека ОДНА (`syncRoles` в карточке сотрудника), поэтому здесь
     * тоже заменяем, а не добавляем второй: два набора прав на одного
     * человека — это вопрос «по какому из них его судить», на который нет
     * ответа. Роль admin не раздаём: её назначает только карточка сотрудника,
     * где есть защита последнего админа.
     */
    public function addToRole(Request $request, Role $role): RedirectResponse
    {
        $this->guard($request);

        abort_if($role->name === self::LOCKED_ROLE, 422, 'Роль «СЕО» назначается только в карточке сотрудника.');

        $data = $request->validate([
            'users' => ['required', 'array'],
            'users.*' => ['integer', 'exists:users,id'],
        ]);

        User::whereIn('id', $data['users'])->get()
            // Админа не трогаем: снять с него роль здесь значит обойти защиту
            // последнего активного админа.
            ->reject(fn (User $u) => $u->hasRole('admin'))
            ->each->syncRoles([$role->name]);

        return back()->with('success', 'Сотрудники добавлены в роль.');
    }

    /** Убрать человека из роли: остаётся без роли, пока ему не назначат другую. */
    public function removeFromRole(Request $request, Role $role, User $user): RedirectResponse
    {
        $this->guard($request);

        abort_if($user->hasRole('admin'), 422, 'Роль «СЕО» снимается только в карточке сотрудника.');

        $user->removeRole($role->name);

        return back()->with('success', 'Сотрудник убран из роли.');
    }

    /** Переименовать роль: меняется подпись, код остаётся. */
    public function renameRole(Request $request, Role $role): RedirectResponse
    {
        $this->guard($request);

        $data = $request->validate(['label' => ['required', 'string', 'max:80']]);
        $role->update(['label' => $data['label']]);

        return back()->with('success', 'Роль переименована.');
    }

    /**
     * Удалить роль — любую, кроме «СЕО».
     *
     * Владелец удаляет роли сам, включая системные: это его компания и его
     * штатное расписание. Удалил «Снабженца» — вместе с ним ушли его области,
     * а люди остались без роли; завёл заново с тем же кодом — политики,
     * которые на этот код смотрят, снова заработали.
     *
     * «СЕО» (admin) — единственное исключение, и не по вкусу: это
     * суперпользователь через `Gate::before`. Удали его — и в систему не
     * войдёт больше НИКТО, включая того, кто удалял. Такую дверь закрывают
     * снаружи навсегда.
     */
    public function destroyRole(Request $request, Role $role): RedirectResponse
    {
        $this->guard($request);

        if ($role->name === self::LOCKED_ROLE) {
            return back()->with('error', 'Роль «СЕО» удалить нельзя: без неё в систему не войдёт никто.');
        }

        $left = $role->users()->count();

        DB::table('role_module_access')->where('role_id', $role->id)->delete();
        $role->delete();
        AccessScope::flush();

        return back()->with('success', $left > 0
            ? 'Роль удалена. Сотрудников без роли: '.$left.' — назначьте им новую.'
            : 'Роль удалена.');
    }

    /**
     * Личные доступы сотрудника — СВЕРХ роли, а не вместо неё.
     *
     * Права роли отсюда не снимаются: сняли бы — и «почему у него нет того,
     * что есть у всех менеджеров» пришлось бы искать в двух местах сразу.
     * Здесь только добавка: этому менеджеру ещё и отчёты.
     */
    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $this->guard($request);

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        // Админу личные права не выдают и не снимают: у него и так всё.
        abort_if($user->hasRole('admin'), 422, 'У админа и так полный доступ.');

        // Что уже даёт роль — личным правом не дублируем: иначе снятие права
        // у роли тихо оставило бы его человеку персональной копией.
        $fromRoles = $user->getPermissionsViaRoles()->pluck('name')->all();
        $extra = array_values(array_diff($data['permissions'], $fromRoles));

        $user->syncPermissions($extra);

        return back()->with('success', 'Личные доступы сохранены.');
    }
}
