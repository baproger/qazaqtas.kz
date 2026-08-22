<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Читает словарь журнала аудита (config/audit.php).
 *
 * Журнал показывает сырые имена таблиц и полей из БД; здесь они становятся
 * человеческим текстом. Незнакомое имя возвращается как есть — новая таблица
 * не должна ломать страницу, ей просто не хватает подписи в конфиге.
 */
class AuditDictionary
{
    public static function table(?string $name): string
    {
        return config('audit.tables.'.$name, $name ?? '—');
    }

    public static function field(?string $name): string
    {
        return config('audit.fields.'.$name, $name ?? '—');
    }

    /** Значение по-русски: словарь, дата — d.m.Y, деньги — с разрядами. */
    public static function value(?string $field, ?string $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        if ($field && ($mapped = config('audit.values.'.$field.'.'.$v)) !== null) {
            return $mapped;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $v)) {
            $moment = Carbon::parse($v);

            // Дата без времени хранится как 00:00:00 — «21.08.2026 00:00»
            // читается как «ночью», хотя времени там просто нет.
            return $moment->format('H:i:s') === '00:00:00'
                ? $moment->format('d.m.Y')
                : $moment->format('d.m.Y H:i');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return Carbon::parse($v)->format('d.m.Y');
        }

        if ($field && in_array($field, (array) config('audit.money', []), true) && is_numeric($v)) {
            return number_format((float) $v, 0, ',', ' ').' ₸';
        }

        return $v;
    }
}
