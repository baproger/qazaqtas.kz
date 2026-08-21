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
        'company_name' => 'QAZAQ TAS',
        'currency' => '₸',
        'auto_create_project' => true,
        'default_locale' => \App\Support\Locales::FALLBACK,
        // bonus_percent удалён: бонус теперь ступенчатый от маржи сделки
        // (PayrollService::bonusRateForMargin), настройкой не регулируется.
        'tax_percent' => 3,
        // Наценка на товар со склада по умолчанию (у позиции может быть своя)
        // и ставка бонуса менеджера от наценки проданного товара.
        'material_markup_percent' => 0,
        // Бонус менеджера: ставка от остатка сделки. Своё производство — 1%,
        // перепродажа — 2% (правило владельца от 21.08.2026).
        'bonus_sale_percent' => 1,
        'bonus_resale_percent' => 2,
        // Производство: бонус за сделанный объём. Ставка бригадира названа
        // владельцем; ставку рабочего он задаёт сам — выдумывать её нельзя.
        'foreman_rate_m2' => 450,
        'foreman_rate_pcs' => 35,
        'worker_rate_m2' => 0,
        'worker_rate_pcs' => 0,
        // 3D-конфигуратор двора на сайте: пока выключен, включается здесь.
        'configurator_enabled' => false,
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
            'default_locale' => ['required', \Illuminate\Validation\Rule::in(\App\Support\Locales::ALL)],
            'tax_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            // sometimes: форму настроек присылают и без этих полей (старые
            // экраны, тесты) — отсутствие поля не должно ронять сохранение.
            'material_markup_percent' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'bonus_sale_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'bonus_resale_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'foreman_rate_m2' => ['sometimes', 'numeric', 'min:0'],
            'foreman_rate_pcs' => ['sometimes', 'numeric', 'min:0'],
            'worker_rate_m2' => ['sometimes', 'numeric', 'min:0'],
            'worker_rate_pcs' => ['sometimes', 'numeric', 'min:0'],
            'configurator_enabled' => ['boolean'],
        ]);

        foreach ($validated as $key => $value) {
            Setting::set($key, $value);
        }

        return back()->with('success', 'Настройки сохранены.');
    }
}
