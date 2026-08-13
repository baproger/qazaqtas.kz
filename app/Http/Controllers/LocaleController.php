<?php

namespace App\Http\Controllers;

use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Переключение языка в ERP.
 *
 * На витрине язык задаёт адрес (`/ru/...`), и переключатель там — обычная
 * ссылка на ту же страницу в другом языке. Здесь же выбор сохраняется в
 * сессии и в карточке сотрудника, чтобы держаться между входами.
 */
class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', Rule::in(Locales::ALL)],
        ]);

        $request->session()->put('locale', $validated['locale']);

        if ($user = $request->user()) {
            $user->update(['language' => $validated['locale']]);
        }

        return back();
    }
}
