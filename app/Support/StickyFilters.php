<?php

namespace App\Support;

use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Фильтр переживает уход со страницы.
 *
 * Раньше он жил ТОЛЬКО в адресной строке: отобрал сделки по Шымкенту, открыл
 * карточку, вернулся через меню — а меню ведёт на голый `/deals`, и отбор
 * пропал. Кнопка «Назад» его возвращала, клик по меню нет, и правило понять
 * было нельзя.
 *
 * Теперь набор запоминается в сессии на пользователя и применяется сам, когда
 * страницу открыли БЕЗ параметров.
 *
 * Два правила, без которых это ловушка:
 *
 * 1. **Восстановленный фильтр виден.** Открыл «Сделки», увидел три штуки
 *    вместо ста и решил, что данные пропали, — хуже, чем потерянный фильтр.
 *    Помечаем ответ флагом, шапка показывает плашку «Фильтр сохранён · сбросить».
 * 2. **Сброс сильнее памяти.** «Сбросить» шлёт `clear=1` и стирает сохранённое.
 *    Иначе пустой набор параметров не отличить от «пришёл впервые», и фильтр
 *    возвращался бы сразу после сброса.
 */
final class StickyFilters
{
    /**
     * Достать фильтр запроса: из адреса или, если его там нет, из памяти.
     *
     * Восстановленные значения кладутся ОБРАТНО в запрос (`merge`), чтобы
     * остальной код читал их привычным `$request->only(...)` и не знал про
     * сессию вовсе.
     *
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    public static function apply(Request $request, string $key, array $fields): array
    {
        $slot = 'filters.'.$key;

        if ($request->boolean('clear')) {
            $request->session()->forget($slot);

            return [];
        }

        $fromUrl = array_filter(
            $request->only($fields),
            fn ($value) => $value !== null && $value !== '',
        );

        // Пользователь сам что-то выбрал — это и есть новая память.
        if ($fromUrl !== []) {
            $request->session()->put($slot, $fromUrl);

            return $fromUrl;
        }

        // Пришёл по ссылке с параметрами, но все пустые — это осознанное «всё».
        if ($request->hasAny($fields)) {
            $request->session()->forget($slot);

            return [];
        }

        $saved = $request->session()->get($slot, []);
        if ($saved === []) {
            return [];
        }

        $request->merge($saved);
        Inertia::share('stickyFilter', ['page' => $key, 'count' => count($saved)]);

        return $saved;
    }
}
