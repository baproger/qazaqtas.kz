<?php

namespace App\Http\Controllers;

use App\Models\CustomField;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomFieldController extends Controller
{
    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->hasRole('admin') || $request->user()->can('setting.update'), 403);
    }

    public function index(Request $request): Response
    {
        $this->authorizeManage($request);

        return Inertia::render('Settings/CustomFields', [
            'fields' => CustomField::orderBy('entity_type')->orderBy('order')->get(),
            'entities' => ['deal' => 'Сделка', 'project' => 'Проект', 'task' => 'Задача'],
            'types' => ['text', 'number', 'date', 'boolean', 'select', 'radio', 'email', 'phone', 'url'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        CustomField::create($this->validated($request));

        return back()->with('success', 'Поле создано.');
    }

    public function update(Request $request, CustomField $customField): RedirectResponse
    {
        $this->authorizeManage($request);
        $customField->update($this->validated($request));

        return back()->with('success', 'Поле обновлено.');
    }

    public function destroy(Request $request, CustomField $customField): RedirectResponse
    {
        $this->authorizeManage($request);
        $customField->delete();

        return back()->with('success', 'Поле удалено.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'entity_type' => ['required', Rule::in(['deal', 'project', 'task'])],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['text', 'number', 'date', 'boolean', 'select', 'radio', 'email', 'phone', 'url'])],
            'required' => ['boolean'],
            'unique' => ['boolean'],
            'is_visible' => ['boolean'],
            'options' => ['nullable', 'array'],
            'order' => ['nullable', 'integer'],
        ]);
        $data['order'] ??= 0;

        return $data;
    }
}
