<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\AiKey;
use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    private array $defaults = [
        'company_name' => 'QAZAQ TAS',
        'currency' => '₸',
        'auto_create_project' => true,
        'default_locale' => Locales::FALLBACK,
        // bonus_percent удалён: ставка бонуса зависит от типа сделки и
        // задаётся ниже (bonus_sale_percent / bonus_resale_percent).
        'tax_percent' => 3,
        // Наценка на товар со склада по умолчанию (у позиции может быть
        // своя): цена продажи = закуп + наценка. Отдельного бонуса «процент
        // от наценки» больше нет — за перепродажу платит ставка типа сделки.
        'material_markup_percent' => 0,
        // Бонус менеджера: ставка от остатка сделки. Своё производство — 1%,
        // перепродажа — 2% (правило владельца от 21.08.2026).
        'bonus_sale_percent' => 1,
        'bonus_resale_percent' => 2,
        // Производство: бонус за сделанный объём. Ставка бригадира названа
        // владельцем; ставку рабочего он задаёт сам — выдумывать её нельзя.
        'foreman_rate_m2' => 450,
        'foreman_rate_pcs' => 35,
        // Размер шрифта ERP: одна ручка на всё приложение. Вся вёрстка в rem,
        // поэтому меняется корневой размер — и за ним всё остальное.
        'ui_font_size' => 'normal',
    ];

    /** Допустимые размеры шрифта: ключ → px корня (см. resources/css/soft.css). */
    public const FONT_SIZES = ['compact', 'normal', 'large', 'xlarge'];

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

        return Inertia::render('Settings/General', [
            'settings' => $settings,
            // Ключ ИИ в браузер не отдаём: только «задан/нет», хвост для
            // узнавания и откуда он взят. Менять ключ вправе лишь админ —
            // это доступ к платному API от лица компании.
            'aiKey' => [
                'set' => AiKey::isSet(),
                'tail' => AiKey::tail(),
                'source' => AiKey::source(),
                'provider' => AiKey::provider(),
                'canEdit' => $request->user()->hasRole('admin'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'max:10'],
            'auto_create_project' => ['boolean'],
            'default_locale' => ['required', Rule::in(Locales::ALL)],
            'tax_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            // sometimes: форму настроек присылают и без этих полей (старые
            // экраны, тесты) — отсутствие поля не должно ронять сохранение.
            'material_markup_percent' => ['sometimes', 'numeric', 'min:0', 'max:1000'],
            'bonus_sale_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'bonus_resale_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'foreman_rate_m2' => ['sometimes', 'numeric', 'min:0'],
            'foreman_rate_pcs' => ['sometimes', 'numeric', 'min:0'],
            'ui_font_size' => ['sometimes', Rule::in(self::FONT_SIZES)],
        ]);

        foreach ($validated as $key => $value) {
            Setting::set($key, $value);
        }

        $this->saveAiKey($request);

        return back()->with('success', 'Настройки сохранены.');
    }

    /**
     * Ключ ИИ: сохраняем только если админ его прислал. Пустое поле —
     * не «стереть», а «не менять»: иначе ключ пропадал бы при каждом
     * сохранении соседней настройки.
     */
    private function saveAiKey(Request $request): void
    {
        if (! $request->user()->hasRole('admin')) {
            return;
        }

        $request->validate([
            'anthropic_key' => ['nullable', 'string', 'max:255'],
            'anthropic_key_clear' => ['sometimes', 'boolean'],
        ]);

        if ($request->boolean('anthropic_key_clear')) {
            AiKey::forget();

            return;
        }

        $key = trim((string) $request->input('anthropic_key'));

        if ($key !== '') {
            AiKey::save($key);
        }
    }
}
