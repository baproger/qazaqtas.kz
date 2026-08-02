<?php

namespace App\Http\Controllers;

use App\Http\Requests\DepartmentRequest;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DepartmentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Department::class);

        $departments = Department::query()
            ->withCount('members')
            ->with('head:id,name')
            ->when($request->string('search')->toString(), fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Departments/Index', [
            'departments' => $departments,
            'filters' => $request->only('search'),
            // Для селекта «Руководитель» в модалке отдела.
            'users' => \App\Models\User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'department_id']),
            'can' => [
                'create' => $request->user()->can('create', Department::class),
                'update' => $request->user()->can('update', Department::class),
                'delete' => $request->user()->can('delete', Department::class),
            ],
        ]);
    }

    public function store(DepartmentRequest $request): RedirectResponse
    {
        $this->authorize('create', Department::class);
        Department::create($request->validated());

        return back()->with('success', 'Отдел создан.');
    }

    public function update(DepartmentRequest $request, Department $department): RedirectResponse
    {
        $this->authorize('update', $department);
        $department->update($request->validated());

        return back()->with('success', 'Отдел обновлён.');
    }

    public function destroy(Department $department): RedirectResponse
    {
        $this->authorize('delete', $department);
        $department->delete();

        return back()->with('success', 'Отдел удалён.');
    }
}
