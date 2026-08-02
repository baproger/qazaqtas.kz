<?php

namespace App\Http\Controllers;

use App\Models\DdsEntry;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ДДС — ручная сводка на странице Финансы. Видят admin/director/financist
 * (сама страница Финансы доступна только им); редактируют admin/financist.
 */
class DdsController extends Controller
{
    private function assertCanEdit(Request $request): void
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'financist']), 403, 'ДДС редактирует финансист или администратор.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', 'in:account,debt'],
            'name' => ['required', 'string', 'max:255'],
            'bank' => ['nullable', 'string', 'max:100'],
            'balance' => ['nullable', 'numeric'],
            'receivable' => ['nullable', 'numeric'],
            'amount' => ['nullable', 'numeric'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->assertCanEdit($request);
        $data = $this->validated($request);
        $data['sort'] = ((int) DdsEntry::where('kind', $data['kind'])->max('sort')) + 1;
        DdsEntry::create($data);

        return back()->with('success', 'Строка ДДС добавлена.');
    }

    public function update(Request $request, DdsEntry $entry): RedirectResponse
    {
        $this->assertCanEdit($request);
        $entry->update($this->validated($request));

        return back()->with('success', 'Строка ДДС обновлена.');
    }

    public function destroy(Request $request, DdsEntry $entry): RedirectResponse
    {
        $this->assertCanEdit($request);
        $entry->delete();

        return back()->with('success', 'Строка ДДС удалена.');
    }

    /** Дата сводки (шапка таблицы, как «20.07.2026» в Excel). */
    public function date(Request $request): RedirectResponse
    {
        $this->assertCanEdit($request);
        $data = $request->validate(['dds_date' => ['nullable', 'string', 'max:40']]);
        Setting::set('dds_date', $data['dds_date'] ?? '');

        return back()->with('success', 'Дата ДДС обновлена.');
    }
}
