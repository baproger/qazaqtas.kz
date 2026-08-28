<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\UiTranslation;
use App\Services\CartService;
use App\Support\CurrentCompany;
use App\Support\Locales;
use App\Support\SiteContent;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * Подписи ролей: код → название на языке читателя.
     *
     * Подпись — это русский текст, то есть готовый ключ словаря ERP
     * (§8, принцип gettext). Прогоняем её через словарь: у системных ролей
     * найдётся казахский, у созданной владельцем останется его название —
     * ровно то, что он написал.
     *
     * @return array<string, string>
     */
    private function roleLabels(): array
    {
        $dictionary = UiTranslation::map(app()->getLocale());

        return Role::orderBy('name')->get(['name', 'label'])
            ->mapWithKeys(function (Role $role) use ($dictionary) {
                $title = $role->title();

                return [$role->name => $dictionary['erp.'.$title] ?? $title];
            })->all();
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'language' => $user->language,
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ] : null,
                // Firms the user may work in + the one currently selected (header switcher).
                'companies' => $user ? $user->companies()->where('is_active', true)->orderBy('name')->get(['companies.id', 'name', 'code']) : [],
                'currentCompanyId' => $user ? CurrentCompany::id() : null,
            ],
            /*
             * Подписи ролей — ОДИН источник на всё приложение: код → название.
             *
             * Раньше словарь был зашит в трёх шаблонах сразу, и роль, созданная
             * владельцем через Настройки → Права доступа, показывалась голым
             * кодом («foreman» вместо «Бригадир»). Три копии одного списка
             * разошлись бы и без новых ролей.
             *
             * Отдаём всем, кто вошёл: подпись роли не тайна, а список короткий.
             */
            'roleLabels' => $user ? $this->roleLabels() : (object) [],
            'notifications' => fn () => $user ? [
                'unread' => $user->unreadNotifications()->count(),
                'items' => $user->notifications()->latest()->limit(10)->get()
                    ->map(fn ($n) => [
                        'id' => $n->id,
                        'data' => $n->data,
                        'read_at' => $n->read_at,
                        'created_at' => $n->created_at,
                    ]),
            ] : ['unread' => 0, 'items' => []],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'locale' => app()->getLocale(),
            'translations' => fn () => UiTranslation::map(app()->getLocale()),
            // Язык страницы и её адреса на других языках: из этого фронт
            // собирает переключатель, hreflang и имена маршрутов витрины
            // (у неосновного языка они с префиксом — `ru.site.catalog`).
            'i18n' => [
                'locale' => app()->getLocale(),
                'default' => Locales::default(),
                'available' => Locales::ALL,
                'names' => Locales::NAMES,
                'short' => Locales::SHORT,
                'alternates' => Locales::alternates($request),
            ],
            // Публичный VAPID-ключ Web Push: фронт подписывает браузер на пуши чата.
            'vapidPublicKey' => (string) config('services.webpush.public_key', ''),
            // Контакты и корзина витрины: нужны шапке/подвалу сайта на каждой
            // странице. Ленивые — на страницах ERP не вычисляются.
            'site' => fn () => SiteContent::shared() + [
                'cartCount' => app(CartService::class)->count(),
            ],
        ];
    }
}
