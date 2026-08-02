<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    private array $defaults = [
        'company_name' => 'BAIA Holding',
        'currency' => '₸',
        'auto_create_project' => true,
        'default_locale' => 'ru',
        // bonus_percent удалён: бонус теперь ступенчатый от маржи сделки
        // (PayrollService::bonusRateForMargin), настройкой не регулируется.
        'tax_percent' => 3,
    ];

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->hasRole('admin') || $request->user()->can('setting.update'), 403);
    }

    public function index(Request $request): Response
    {
        $this->authorizeManage($request);

        $settings = [];
        foreach ($this->defaults as $key => $default) {
            $settings[$key] = Setting::get($key, $default);
        }

        return Inertia::render('Settings/General', ['settings' => $settings]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'max:10'],
            'auto_create_project' => ['boolean'],
            'default_locale' => ['required', 'in:ru,kk'],
            'tax_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        foreach ($validated as $key => $value) {
            Setting::set($key, $value);
        }

        return back()->with('success', 'Настройки сохранены.');
    }
}
