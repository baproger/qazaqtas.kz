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
                'contacts' => SiteContent::contacts(),
                'branches' => SiteContent::branches(),
                'delivery' => SiteContent::delivery(),
                'faq' => SiteContent::faq(),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->guard($request);

        $data = $request->validate([
            'phone' => ['required', 'string', 'max:40'],
            'whatsapp' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:120'],
            'hours' => ['nullable', 'string', 'max:120'],
            'instagram' => ['nullable', 'string', 'max:255'],

            'branches' => ['array', 'max:10'],
            'branches.*.city' => ['required', 'string', 'max:80'],
            'branches.*.role' => ['nullable', 'string', 'max:120'],
            'branches.*.address' => ['nullable', 'string', 'max:255'],
            'branches.*.phone' => ['nullable', 'string', 'max:40'],

            'delivery' => ['array', 'max:20'],
            'delivery.*.city' => ['required', 'string', 'max:80'],
            'delivery.*.base' => ['required', 'numeric', 'min:0'],
            'delivery.*.per_km' => ['required', 'numeric', 'min:0'],
            'delivery.*.free_from' => ['required', 'numeric', 'min:0'],

            'faq' => ['array', 'max:20'],
            'faq.*.q' => ['required', 'string', 'max:255'],
            'faq.*.a' => ['required', 'string', 'max:2000'],
        ]);

        Setting::set('site_phone', $data['phone']);
        // В ссылку wa.me уходят только цифры — храним как ввели, чистим при чтении.
        Setting::set('whatsapp_phone', $data['whatsapp']);
        Setting::set('site_email', $data['email'] ?? '');
        Setting::set('site_hours', $data['hours'] ?? '');
        Setting::set('site_instagram', $data['instagram'] ?? '');
        Setting::set('site_branches', array_values($data['branches'] ?? []));
        Setting::set('site_delivery', array_values($data['delivery'] ?? []));
        Setting::set('site_faq', array_values($data['faq'] ?? []));

        return back()->with('success', 'Настройки сайта сохранены.');
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
