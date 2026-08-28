<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Структура компании: дерево отделов, руководители и люди.
 *
 * Это не украшение оргсхемы. Дерево отвечает на вопрос, который задаёт
 * страница «Права доступа»: что значит «отдел» и «отдел и подчинённые»
 * (`App\Support\AccessScope`). Пока структура плоская, обе эти области
 * совпадают со «своими», и руководителю отдела нечего показать.
 */
class CompanyStructureController extends Controller
{
    private function guard(Request $request): void
    {
        abort_unless($request->user()?->can('department.viewAny'), 403);
    }

    private function guardEdit(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(['admin', 'director', 'financist']), 403);
    }

    public function index(Request $request): Response
    {
        $this->guard($request);

        $people = User::where('is_active', true)
            ->with('roles:id,name,label')
            ->orderBy('name')
            ->get(['id', 'name', 'avatar', 'department_id']);

        // Ключ группы — строка, а не null: PHP 8.5 ругается на null в качестве
        // индекса массива, а людей без отдела как раз группирует он.
        $byDepartment = $people->groupBy(fn (User $u) => $u->department_id === null ? '' : (string) $u->department_id);

        $departments = Department::where('is_active', true)
            ->orderBy('sort')->orderBy('name')
            ->get(['id', 'parent_id', 'sort', 'name', 'description', 'head_user_id']);

        return Inertia::render('Settings/Structure', [
            'departments' => $departments->map(fn (Department $d) => [
                'id' => $d->id,
                'parent_id' => $d->parent_id,
                'name' => $d->name,
                'description' => $d->description,
                'head' => $d->head_user_id ? $people->firstWhere('id', $d->head_user_id)?->only(['id', 'name', 'avatar']) : null,
                'head_user_id' => $d->head_user_id,
                // Люди отдела: карточка должна отвечать «кто здесь», не
                // открывая ещё одну страницу.
                'people' => $byDepartment->get((string) $d->id, collect())->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'avatar' => $u->avatar,
                    'role' => $u->roles->first()?->title(),
                ])->values(),
            ])->values(),
            // Люди без отдела: их не видно в дереве, и без этого списка они
            // тихо выпадают из структуры навсегда.
            'unassigned' => $byDepartment->get('', collect())
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'avatar' => $u->avatar,
                    'role' => $u->roles->first()?->title()])->values(),
            // Для выбора людей: аватар и отдел, чтобы в списке было видно,
            // кого добавляешь и откуда он уйдёт.
            'people' => $people->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'avatar' => $u->avatar,
                'department_id' => $u->department_id,
                'department' => $departments->firstWhere('id', $u->department_id)?->name,
            ])->values(),
            'company' => Setting::get('company_name', 'QAZAQ TAS'),
            'can' => ['manage' => $request->user()->hasAnyRole(['admin', 'director', 'financist'])],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guardEdit($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:departments,id'],
            'head_user_id' => ['nullable', 'exists:users,id'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        Department::create($data + ['is_active' => true]);

        return back()->with('success', 'Отдел добавлен.');
    }

    public function update(Request $request, Department $department): RedirectResponse
    {
        $this->guardEdit($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'exists:departments,id'],
            'head_user_id' => ['nullable', 'exists:users,id'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        // Отдел не может быть подчинён самому себе или своему потомку: такая
        // ссылка рвёт дерево, и обход subtreeIds крутился бы по кругу.
        if ($data['parent_id'] !== null && in_array((int) $data['parent_id'], $department->subtreeIds(), true)) {
            return back()->with('error', 'Отдел нельзя подчинить самому себе или своему подразделению.');
        }

        $department->update($data);

        return back()->with('success', 'Отдел обновлён.');
    }

    public function destroy(Request $request, Department $department): RedirectResponse
    {
        $this->guardEdit($request);

        // Подчинённые отделы поднимаем на уровень выше, а не удаляем следом:
        // каскад унёс бы половину структуры одним кликом.
        Department::where('parent_id', $department->id)->update(['parent_id' => $department->parent_id]);
        User::where('department_id', $department->id)->update(['department_id' => null]);
        $department->delete();

        return back()->with('success', 'Отдел удалён, подразделения подняты на уровень выше.');
    }

    /** Перевести человека в отдел — перетаскиванием в дереве. */
    public function assign(Request $request, User $user): RedirectResponse
    {
        $this->guardEdit($request);

        $data = $request->validate([
            'department_id' => ['nullable', Rule::exists('departments', 'id')],
        ]);

        $user->update(['department_id' => $data['department_id']]);

        return back()->with('success', 'Сотрудник переведён.');
    }
}
