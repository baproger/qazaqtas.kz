<?php

namespace App\Http\Controllers;

use App\Models\UiTranslation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TranslationController extends Controller
{
    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->hasRole('admin') || $request->user()->can('setting.update'), 403);
    }

    public function index(Request $request): Response
    {
        $this->authorizeManage($request);

        return Inertia::render('Settings/Translations', [
            'items' => UiTranslation::orderBy('group')->orderBy('key')->get(['id', 'key', 'group', 'ru', 'kk']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'items' => ['array'],
            'items.*.id' => ['required', 'exists:ui_translations,id'],
            'items.*.ru' => ['nullable', 'string', 'max:1000'],
            'items.*.kk' => ['nullable', 'string', 'max:1000'],
        ]);

        foreach ($data['items'] ?? [] as $row) {
            UiTranslation::whereKey($row['id'])->update(['ru' => $row['ru'], 'kk' => $row['kk']]);
        }
        UiTranslation::flushCache();

        return back()->with('success', 'Переводы сохранены.');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $data = $request->validate([
            'key' => ['required', 'string', 'max:150', 'unique:ui_translations,key'],
            'group' => ['nullable', 'string', 'max:50'],
            'ru' => ['nullable', 'string', 'max:1000'],
            'kk' => ['nullable', 'string', 'max:1000'],
        ]);
        $data['group'] ??= 'common';

        UiTranslation::create($data);

        return back()->with('success', 'Ключ добавлен.');
    }

    public function destroy(Request $request, UiTranslation $translation): RedirectResponse
    {
        $this->authorizeManage($request);
        $translation->delete();

        return back()->with('success', 'Ключ удалён.');
    }
}
