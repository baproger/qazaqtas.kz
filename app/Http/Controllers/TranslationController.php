<?php

namespace App\Http\Controllers;

use App\Models\UiTranslation;
use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Настройки → Переводы.
 *
 * Страница показывает НЕ только строки из базы, а весь словарь: тексты из
 * `lang/{kk,ru}/*.php`, что едут вместе с кодом, и правки владельца поверх
 * них. Иначе переводить через админку было бы нечего — базовый текст живёт
 * в репозитории, и до него из ERP не дотянуться.
 *
 * Правка сохраняется отдельной строкой в `ui_translations` и перекрывает
 * файл. Убрали правку — вернулся текст из поставки.
 */
class TranslationController extends Controller
{
    /** Сколько строк отдаём за раз: в словаре их больше тысячи. */
    private const PAGE = 150;

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()->hasRole('admin') || $request->user()->can('setting.update'), 403);
    }

    public function index(Request $request): Response
    {
        $this->authorizeManage($request);

        $group = (string) $request->query('group', 'site');
        $search = trim((string) $request->query('search', ''));

        $rows = $this->rows($group, $search);

        return Inertia::render('Settings/Translations', [
            'groups' => $this->groups(),
            'locales' => Locales::forForm(),
            'filters' => ['group' => $group, 'search' => $search],
            'total' => count($rows),
            'items' => array_slice($rows, 0, self::PAGE),
            'limit' => self::PAGE,
        ]);
    }

    /**
     * Строки словаря: поставочный текст + правка владельца, если она есть.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $group, string $search): array
    {
        $overrides = UiTranslation::query()
            ->where('group', $group)
            ->get(['id', 'key', ...Locales::ALL])
            ->keyBy('key');

        $rows = [];

        foreach ($this->shippedKeys($group) as $name) {
            // Хранится и ищется полный ключ («erp.Сохранить») — именно его
            // ждёт UiTranslation::map. Показываем при этом короткий: с
            // префиксом группы список читался бы вдвое хуже.
            $key = "$group.$name";

            $shipped = [];
            foreach (Locales::ALL as $locale) {
                $shipped[$locale] = $this->shippedValue($group, $name, $locale);
            }

            $override = $overrides->get($key);

            $rows[] = [
                'key' => $key,
                'name' => $name,
                'group' => $group,
                'shipped' => $shipped,
                'override' => collect(Locales::ALL)
                    ->mapWithKeys(fn (string $l) => [$l => $override?->{$l} ?? ''])
                    ->all(),
                'id' => $override?->id,
            ];
        }

        // Ключи, заведённые вручную и не встречающиеся в файлах, тоже нужны:
        // иначе владелец не смог бы их найти и убрать.
        foreach ($overrides as $key => $override) {
            if (! in_array($key, array_column($rows, 'key'), true)) {
                $rows[] = [
                    'key' => $key,
                    'name' => str_starts_with($key, "$group.") ? substr($key, strlen($group) + 1) : $key,
                    'group' => $group,
                    'shipped' => array_fill_keys(Locales::ALL, ''),
                    'override' => collect(Locales::ALL)
                        ->mapWithKeys(fn (string $l) => [$l => $override->{$l} ?? ''])
                        ->all(),
                    'id' => $override->id,
                ];
            }
        }

        if ($search !== '') {
            $rows = array_values(array_filter($rows, function (array $row) use ($search) {
                $haystack = $row['name'].' '.implode(' ', $row['shipped']).' '.implode(' ', $row['override']);

                return mb_stripos($haystack, $search) !== false;
            }));
        }

        usort($rows, fn (array $a, array $b) => strcmp($a['key'], $b['key']));

        return $rows;
    }

    /**
     * Разделы словаря: файлы поставки плюс группы, заведённые в базе.
     *
     * Вторые — это ключи, добавленные вручную (и старые из сидера). Без них
     * такая строка попала бы в базу и пропала из списка: найти и снять её
     * стало бы невозможно.
     */
    private function groups(): array
    {
        $fromDatabase = UiTranslation::query()
            ->whereNotIn('group', UiTranslation::groups())
            ->distinct()->pluck('group')->all();

        return collect([...UiTranslation::groups(), ...$fromDatabase])
            ->map(fn (string $group) => [
                'code' => $group,
                'label' => UiTranslation::GROUP_LABELS[$group] ?? $group,
                'count' => count($this->shippedKeys($group))
                    ?: UiTranslation::where('group', $group)->count(),
            ])
            ->all();
    }

    /** Ключи группы, собранные из файлов всех языков. */
    private function shippedKeys(string $group): array
    {
        $keys = [];

        foreach (Locales::ALL as $locale) {
            $lines = Lang::get($group, [], $locale);

            if (is_array($lines)) {
                $keys = [...$keys, ...array_keys(Arr::dot($lines))];
            }
        }

        return array_values(array_unique($keys));
    }

    private function shippedValue(string $group, string $name, string $locale): string
    {
        $lines = Lang::get($group, [], $locale);
        $value = is_array($lines) ? Arr::get($lines, $name) : null;

        // У интерфейса ERP ключ и есть русский текст: файла `lang/ru/erp.php`
        // нет и не нужно, поставочное значение там — сам ключ.
        if (! is_string($value) && $group === 'erp' && $locale === 'ru') {
            return $name;
        }

        return is_string($value) ? $value : '';
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $rules = [
            'items' => ['array'],
            'items.*.key' => ['required', 'string', 'max:500'],
            'items.*.group' => ['required', 'string', 'max:50'],
        ];

        foreach (Locales::ALL as $locale) {
            $rules["items.*.$locale"] = ['nullable', 'string', 'max:2000'];
        }

        $data = $request->validate($rules);

        foreach ($data['items'] ?? [] as $row) {
            $values = [];
            foreach (Locales::ALL as $locale) {
                $values[$locale] = trim((string) ($row[$locale] ?? ''));
            }

            // Пустая правка — это «вернуть как в поставке», а не «пустой
            // текст»: строку убираем, и снова начинает работать файл.
            if (! array_filter($values, fn (string $v) => $v !== '')) {
                UiTranslation::where('key', $row['key'])->delete();

                continue;
            }

            UiTranslation::updateOrCreate(
                ['key' => $row['key']],
                ['group' => $row['group']] + $values,
            );
        }

        UiTranslation::flushCache();

        return back()->with('success', 'Переводы сохранены.');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);

        $rules = [
            'key' => ['required', 'string', 'max:500', 'unique:ui_translations,key'],
            'group' => ['nullable', 'string', 'max:50'],
        ];

        foreach (Locales::ALL as $locale) {
            $rules[$locale] = ['nullable', 'string', 'max:2000'];
        }

        $data = $request->validate($rules);
        $data['group'] ??= 'site';

        UiTranslation::create($data);

        return back()->with('success', 'Ключ добавлен.');
    }

    public function destroy(Request $request, UiTranslation $translation): RedirectResponse
    {
        $this->authorizeManage($request);
        $translation->delete();

        return back()->with('success', 'Правка снята — вернулся текст из поставки.');
    }
}
