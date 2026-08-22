<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\DealGuards;
use App\Models\Deal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Кто ведёт сделку и по какой ставке считается его бонус.
 *
 * Два поля, но оба про деньги и права: ответственного меняет владелец сделки
 * (и не после «Акта»), ручной процент бонуса — только финансист или админ.
 */
class DealAssignmentController extends Controller
{
    use DealGuards;

    /**
     * Ручной % бонуса менеджера по сделке — ставит ТОЛЬКО финансист/админ.
     * null (пустое поле) = вернуть ставку по типу сделки (Настройки).
     */
    public function updateBonusRate(Request $request, Deal $deal): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'financist']), 403, 'Процент бонуса меняет финансист или администратор.');
        $validated = $request->validate(['bonus_rate_override' => ['nullable', 'numeric', 'min:0', 'max:100']]);

        $deal->update(['bonus_rate_override' => $validated['bonus_rate_override'] ?? null]);

        return back()->with('success', isset($validated['bonus_rate_override'])
            ? 'Бонус менеджера по сделке: '.rtrim(rtrim(number_format((float) $validated['bonus_rate_override'], 2, '.', ''), '0'), '.').'% (вручную).'
            : 'Бонус менеджера: автоматически по ставке типа сделки.');
    }

    public function updateResponsible(Request $request, Deal $deal): RedirectResponse
    {
        // Only the owner (or leadership) may (re)assign the responsible person.
        $this->authorize('update', $deal);
        $this->assertNotFrozen($request, $deal);
        $validated = $request->validate(['responsible_user_id' => ['nullable', 'exists:users,id']]);
        $deal->update(['responsible_user_id' => $validated['responsible_user_id'] ?: null]);

        return back()->with('success', 'Ответственный изменён.');
    }
}
