<?php

namespace App\Models\Concerns;

use App\Support\Locales;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Переводимые поля записи.
 *
 * Базовая колонка остаётся значением по умолчанию, а таблица переводов
 * перекрывает её для конкретного языка. Пустой перевод — это НЕ перевод:
 * поле, оставленное незаполненным, откатывается к базовому значению, иначе
 * недозаполненная карточка показывала бы покупателю пустое место.
 *
 * Подстановка НЕ прячется в toArray(): её вызывает витрина через localized(),
 * а ERP получает запись как есть. Иначе форма редактирования показала бы
 * переведённый текст и сохранила его обратно в базовую колонку — язык-оригинал
 * потерялся бы молча. Забытый localized() на витрине — это всего лишь
 * непереведённая строка, и её видно сразу.
 *
 * Модель обязана объявить translatable() и translations().
 */
trait HasTranslations
{
    /** Переводы, разобранные по языкам, в пределах запроса. */
    private ?array $translationCache = null;

    /** Поля, которые видит покупатель и которые поэтому переводятся. */
    abstract protected static function translatable(): array;

    abstract public function translations(): HasMany;

    /**
     * Значение поля на языке (по умолчанию — языке страницы).
     * Откат: перевод языка → базовая колонка.
     */
    public function tr(string $field, ?string $locale = null): mixed
    {
        $locale ??= app()->getLocale();
        $value = $this->translationFor($locale)[$field] ?? null;

        return static::isTranslated($value) ? $value : $this->getAttribute($field);
    }

    /**
     * Запись для витрины: переводимые поля подменены на язык страницы.
     *
     * Загруженные связи проходят ту же обработку — иначе у товара переводилось
     * бы название, а у его категории рядом оставалось базовое.
     */
    public function localized(): array
    {
        $data = $this->toArray();

        foreach (static::translatable() as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->tr($field);
            }
        }

        foreach ($this->getRelations() as $name => $relation) {
            $key = Str::snake($name);

            if (! array_key_exists($key, $data)) {
                continue;
            }

            if (static::isTranslatable($relation)) {
                $data[$key] = $relation->localized();
            } elseif ($relation instanceof Collection) {
                $data[$key] = $relation
                    ->map(fn ($item) => static::isTranslatable($item) ? $item->localized() : $item)
                    ->all();
            }
        }

        return $data;
    }

    /** Все переводимые поля разом — для формы в ERP. */
    public function translationsPayload(): array
    {
        $payload = [];

        foreach (Locales::ALL as $locale) {
            $row = $this->translationFor($locale);

            foreach (static::translatable() as $field) {
                $payload[$locale][$field] = $row[$field] ?? null;
            }
        }

        return $payload;
    }

    /**
     * Сохраняет присланные переводы. Полностью пустой язык удаляется, чтобы
     * в базе не копились строки-пустышки, которые ничего не перекрывают.
     */
    public function saveTranslations(?array $input): void
    {
        foreach (Locales::ALL as $locale) {
            $row = [];

            foreach (static::translatable() as $field) {
                $value = $input[$locale][$field] ?? null;
                $row[$field] = static::isTranslated($value) ? $value : null;
            }

            if (array_filter($row, static::isTranslated(...))) {
                $this->translations()->updateOrCreate(['locale' => $locale], $row);
            } else {
                $this->translations()->where('locale', $locale)->delete();
            }
        }

        $this->translationCache = null;
        $this->unsetRelation('translations');
    }

    /** Строка перевода языка как массив полей. */
    private function translationFor(string $locale): array
    {
        if ($this->translationCache === null) {
            $rows = $this->relationLoaded('translations')
                ? $this->translations
                : $this->translations()->get();

            $this->translationCache = $rows->keyBy('locale')->map->attributesToArray()->all();
        }

        return $this->translationCache[$locale] ?? [];
    }

    /**
     * Пустая строка, null и пустой массив переводом не считаются — там,
     * где перевода нет, должно остаться базовое значение.
     */
    private static function isTranslated(mixed $value): bool
    {
        return is_array($value) ? $value !== [] : ($value !== null && trim((string) $value) !== '');
    }

    private static function isTranslatable(mixed $value): bool
    {
        return is_object($value) && in_array(self::class, class_uses_recursive($value), true);
    }
}
