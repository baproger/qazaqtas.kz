<?php

namespace App\Http\Controllers;

use App\Support\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    /**
     * Header switcher: change the current firm without re-login.
     */
    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate(['company_id' => ['required', 'integer', 'min:0']]);
        $id = (int) $validated['company_id'];

        // 0 = «Все компании» — общий отчёт по обеим фирмам (бухгалтер/админ).
        if ($id === 0) {
            abort_unless($request->user()->hasAnyRole(['admin', 'financist']), 403, 'Режим «Все компании» доступен бухгалтеру и админу.');
            CurrentCompany::set(0);

            return $this->backToSamePage($request)->with('success', 'Режим «Все компании»: общий отчёт по обеим фирмам.');
        }

        abort_unless(
            $request->user()->companies()->where('companies.id', $id)->where('is_active', true)->exists(),
            403,
            'Вы не привязаны к этой компании.'
        );

        CurrentCompany::set($id);

        return $this->backToSamePage($request)->with('success', 'Компания переключена.');
    }

    /**
     * После переключения фирмы остаёмся на ТОЙ ЖЕ странице (данные
     * перезагрузятся уже в новой компании). Исключение — карточка конкретной
     * сделки/заказа: она принадлежит прежней фирме и дала бы 403, поэтому
     * с неё уводим в соответствующий список.
     */
    private function backToSamePage(Request $request): RedirectResponse
    {
        $path = (string) (parse_url((string) $request->headers->get('referer'), PHP_URL_PATH) ?? '');

        if (preg_match('#^/deals/\d+#', $path)) {
            return redirect()->route('deals.index');
        }
        if (preg_match('#^/projects/\d+#', $path)) {
            return redirect()->route('projects.index');
        }

        return $path !== '' ? redirect($path) : redirect()->route('dashboard');
    }
}
