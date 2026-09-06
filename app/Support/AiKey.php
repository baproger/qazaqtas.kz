<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Ключ Anthropic: сначала из Настроек ERP, потом из .env.
 *
 * Владельцу неудобно лезть на сервер ради строки в .env, поэтому ключ
 * вводится в Настройках и лежит в базе ЗАШИФРОВАННЫМ (APP_KEY). В браузер
 * он не отдаётся никогда — только признак «задан» и последние 4 символа,
 * чтобы человек узнал свой ключ.
 *
 * Переменная окружения остаётся запасным вариантом: если ключ прописан на
 * сервере, а в Настройках пусто, всё продолжает работать.
 */
class AiKey
{
    public const SETTING = 'anthropic_key';

    /** Рабочий ключ или null, если не задан нигде. */
    public static function get(): ?string
    {
        $stored = static::stored();

        if ($stored !== null) {
            return $stored;
        }

        return config('services.anthropic.key') ?: null;
    }

    /** Задан ли ключ (в Настройках или в .env). */
    public static function isSet(): bool
    {
        return static::get() !== null;
    }

    /** Хвост ключа для интерфейса: «…a1b2». Полный ключ наружу не уходит. */
    public static function tail(): ?string
    {
        $key = static::get();

        return $key ? mb_substr($key, -4) : null;
    }

    /** Откуда взят ключ — подсказка в интерфейсе. */
    public static function source(): ?string
    {
        return match (true) {
            static::stored() !== null => 'settings',
            (bool) config('services.anthropic.key') => 'env',
            default => null,
        };
    }

    public static function save(string $key): void
    {
        Setting::set(static::SETTING, Crypt::encryptString($key));
    }

    public static function forget(): void
    {
        Setting::set(static::SETTING, null);
    }

    /** Ключ из Настроек, расшифрованный. */
    private static function stored(): ?string
    {
        $raw = Setting::get(static::SETTING);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable $e) {
            // Сменили APP_KEY — старое значение расшифровать нечем.
            // Молчать нельзя: администратор должен ввести ключ заново.
            report($e);

            return null;
        }
    }
}
