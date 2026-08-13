<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Язык запроса.
 *
 * На витрине язык задаёт АДРЕС и только он: `/katalog` — основной язык,
 * `/ru/katalog` — русский. Сохранённый ранее выбор здесь намеренно не
 * учитывается, иначе присланная ссылка открывалась бы не на том языке, на
 * котором её отправили, а поисковик получал бы разный текст по одному URL.
 *
 * В ERP адреса языком не помечены — там язык берётся из карточки сотрудника,
 * затем из сессии, затем из настройки «Язык по умолчанию».
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();
        $siteLocale = Locales::fromRouteName($routeName);

        if ($siteLocale !== null) {
            // Основной язык живёт без префикса. Пришли на `/kk/...`, когда
            // казахский и так основной, — уводим на канонический адрес, чтобы
            // одна страница не индексировалась дважды.
            if ($siteLocale === Locales::default() && $routeName !== Locales::baseRouteName($routeName)) {
                return $this->canonicalRedirect($request, $routeName);
            }

            app()->setLocale($siteLocale);

            return $next($request);
        }

        app()->setLocale($this->erpLocale($request));

        return $next($request);
    }

    /** Язык интерфейса ERP: профиль → сессия → настройка. */
    private function erpLocale(Request $request): string
    {
        $locale = $request->user()?->language;

        if (! Locales::supported($locale) && $request->hasSession()) {
            $locale = $request->session()->get('locale');
        }

        return Locales::supported($locale) ? $locale : Locales::default();
    }

    /** Тот же маршрут, но без языкового префикса в адресе. */
    private function canonicalRedirect(Request $request, string $routeName): Response
    {
        $target = route(
            Locales::baseRouteName($routeName),
            $request->route()->parameters(),
        );

        if ($query = $request->getQueryString()) {
            $target .= '?'.$query;
        }

        // Только для чтения: перенаправлять POST/PATCH значило бы потерять тело.
        return $request->isMethod('GET')
            ? redirect($target, 301)
            : redirect($target, 308);
    }
}
