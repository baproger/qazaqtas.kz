<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\SiteContent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Настройки → Сайт: контакты, филиалы, тарифы доставки и FAQ витрины.
 * Раньше эти значения жили только в коде и правились через базу.
 */
class SiteSettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $this->guard($request);

        return Inertia::render('Settings/Site', [
            'site' => [
                'hero' => SiteContent::heroStyle(),
                'heroSlidesCount' => count(app(\App\Services\CatalogService::class)->heroSlides()),
                'contacts' => SiteContent::contacts(),
                // Форме нужны строки с языковыми суффиксами: она правит
                // оба языка, а не то, что видит витрина.
                'branches' => SiteContent::raw('site_branches'),
                'delivery' => SiteContent::raw('site_delivery'),
                'faq' => SiteContent::raw('site_faq'),
                'hours' => [
                    'base' => Setting::get('site_hours', SiteContent::DEFAULTS['site_hours']),
                    'kk' => Setting::get('site_hours_kk', SiteContent::DEFAULTS['site_hours_kk'] ?? ''),
                    'ru' => Setting::get('site_hours_ru', ''),
                ],
            ],
            'locales' => \App\Support\Locales::forForm(),
            'translatableRows' => SiteContent::TRANSLATABLE_ROWS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->guard($request);

        $data = $request->validate([
            // Необязательное: частичное сохранение настроек не должно
            // сбрасывать оформление первого экрана.
            'hero' => ['nullable', 'string', 'in:'.implode(',', SiteContent::HERO_STYLES)],
            'phone' => ['required', 'string', 'max:40'],
            'whatsapp' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
            'hours' => ['nullable', 'string', 'max:120'],
            'hours_kk' => ['nullable', 'string', 'max:120'],
            'hours_ru' => ['nullable', 'string', 'max:120'],
            // Значение уходит в href плавающей кнопки. Пропускаем только
            // http(s) или голое имя профиля: схема javascript: в ссылке —
            // готовый XSS, и Vue её не экранирует.
            'instagram' => ['nullable', 'string', 'max:255', 'regex:/^(https?:\/\/[^\s]+|@?[A-Za-z0-9._]+)$/'],

            'branches' => ['array', 'max:10'],
            'branches.*.city' => ['required', 'string', 'max:80'],
            'branches.*.role' => ['nullable', 'string', 'max:120'],
            'branches.*.address' => ['nullable', 'string', 'max:255'],
            'branches.*.phone' => ['nullable', 'string', 'max:40'],
            'branches.*.coords' => ['nullable', 'string', 'max:60'],

            'delivery' => ['array', 'max:20'],
            'delivery.*.city' => ['required', 'string', 'max:80'],
            'delivery.*.base' => ['required', 'numeric', 'min:0'],
            'delivery.*.per_km' => ['required', 'numeric', 'min:0'],
            'delivery.*.free_from' => ['required', 'numeric', 'min:0'],

            'faq' => ['array', 'max:20'],
            'faq.*.q' => ['required', 'string', 'max:255'],
            'faq.*.a' => ['required', 'string', 'max:2000'],

            ...$this->translationRules(),
        ]);

        Setting::set('site_hero', $data['hero'] ?? SiteContent::heroStyle());
        Setting::set('site_phone', $data['phone']);
        // В ссылку wa.me уходят только цифры — храним как ввели, чистим при чтении.
        Setting::set('whatsapp_phone', $data['whatsapp']);
        Setting::set('site_email', $data['email'] ?? '');
        Setting::set('site_hours', $data['hours'] ?? '');
        Setting::set('site_hours_kk', $data['hours_kk'] ?? '');
        Setting::set('site_hours_ru', $data['hours_ru'] ?? '');
        Setting::set('site_instagram', $data['instagram'] ?? '');
        Setting::set('site_branches', array_values($data['branches'] ?? []));
        Setting::set('site_delivery', array_values($data['delivery'] ?? []));
        Setting::set('site_faq', array_values($data['faq'] ?? []));

        return back()->with('success', 'Настройки сайта сохранены.');
    }

    /**
     * Правила для языковых версий полей: те же строки под суффиксом языка
     * (`faq.*.q_kk`). Ни одна не обязательна — пустая означает «как в
     * основном поле».
     *
     * @return array<string, mixed>
     */
    private function translationRules(): array
    {
        $rules = [];

        foreach (SiteContent::TRANSLATABLE_ROWS as $key => $fields) {
            $group = str_replace('site_', '', $key);

            // Правятся здесь только три списка; остальные пока живут
            // значениями по умолчанию и своей формы не имеют.
            if (! in_array($group, ['branches', 'delivery', 'faq'], true)) {
                continue;
            }

            foreach ($fields as $field) {
                foreach (\App\Support\Locales::ALL as $locale) {
                    $rules["$group.*.{$field}_{$locale}"] = ['nullable', 'string', 'max:2000'];
                }
            }
        }

        return $rules;
    }

    private function guard(Request $request): void
    {
        abort_unless(
            $request->user()->hasRole('admin') || $request->user()->can('setting.update'),
            403,
            'Настройки сайта меняет админ.'
        );
    }
}
