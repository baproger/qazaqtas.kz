<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Бывший дашборд руководителя. Все блоки (KPI, «требует внимания», воронка,
 * топ менеджеров) переехали на Аналитику — маршрут оставлен ради старых
 * ссылок/закладок и редиректа после логина.
 */
class DashboardController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        $u = $request->user();
        if ($u->hasAnyRole(['admin', 'director', 'financist'])) {
            return redirect()->route('analytics.index');
        }

        return redirect()->route($u->hasRole('manager') ? 'deals.index' : 'projects.index');
    }
}
