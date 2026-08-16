# Дизайн денежных страниц QAZAQ TAS ERP

> Только визуальная система: каркас, сетка, типографика, палитра, компоненты и
> состояния. Стек: Vue 3 + Tailwind. Классы указаны точно — по ним страница
> собирается заново.

---

## 1. Каркас страницы

```
AppLayout  (тёмное боковое меню + светлая верхняя панель)
└── FinanceLayout
    ├── ряд вкладок раздела
    ├── заголовок + пояснение + слот «действия» справа
    └── содержимое
```

Контейнер: `mx-auto max-w-7xl` — широкие страницы; `mx-auto max-w-5xl` — личные.
Внешние отступы даёт `AppLayout`: `p-4 sm:p-6`.

**Вкладки**

```html
<nav class="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200 pb-px">
  <a class="whitespace-nowrap rounded-t-lg px-3 py-2 text-sm font-medium transition-colors duration-150
            border-b-2 border-indigo-600 text-indigo-700">Активная</a>
  <a class="whitespace-nowrap rounded-t-lg px-3 py-2 text-sm font-medium transition-colors duration-150
            border-b-2 border-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-700">Обычная</a>
</nav>
```

**Заголовок и действия**

```html
<div class="mb-4 flex flex-wrap items-end justify-between gap-3">
  <div>
    <h2 class="text-lg font-semibold text-slate-900">Касса</h2>
    <p class="mt-0.5 text-xs text-slate-400">кассовая книга за день</p>
  </div>
  <div class="flex flex-wrap items-center gap-2"><!-- кнопки, фильтры --></div>
</div>
```

---

## 2. Сетка и отступы

| Что | Значение |
|---|---|
| Между секциями | `mt-6` |
| Между плитками | `gap-3` |
| Между карточками списка | `gap-3` |
| Внутри плитки | `p-4` |
| Внутри карточки списка | `p-4` |
| Шапка секции | `px-6 py-4` |
| Ячейка таблицы | первая `px-6 py-2.5`, остальные `px-4 py-2.5` |
| Внутри модалки | `p-6` |

Сетки: плитки `grid-cols-1 sm:grid-cols-3 lg:grid-cols-4`, карточки списка
`grid-cols-1 md:grid-cols-2 xl:grid-cols-3`.

---

## 3. Типографика

| Элемент | Классы |
|---|---|
| Заголовок страницы | `text-lg font-semibold text-slate-900` |
| Пояснение под ним | `text-xs text-slate-400` |
| Заголовок секции | `text-sm font-semibold text-slate-900` |
| Подпись плитки | `text-[11px] uppercase tracking-wide text-slate-400` |
| Цифра плитки | `text-xl font-bold tabular-nums` |
| Крупная сумма в карточке | `text-xl font-bold tabular-nums text-slate-900` |
| Основной текст | `text-sm text-slate-600` |
| Мелкая подпись | `text-[11px] text-slate-400` |
| Шапка таблицы | `text-xs uppercase tracking-wide text-slate-400` |

Любые числа — `tabular-nums`. Деньги форматируются
`Intl.NumberFormat('ru-RU')` и заканчиваются « ₸».

---

## 4. Палитра

| Роль | Цвет |
|---|---|
| Действие, активное состояние | `indigo-600` (ховер `indigo-700`) |
| Приход, успех | `emerald` (`emerald-50` фон, `emerald-600/700` текст) |
| Расход, долг, опасность | `rose` (`rose-50`, `rose-600`) |
| Ожидание, внимание | `amber` (`amber-50`, `amber-700`) |
| Счета, банк | `sky` (`sky-50`, `sky-700`) |
| Текст и рамки | `slate` (`slate-900/600/500/400/300`, рамки `slate-200/100`) |
| Тёмная итоговая плитка | `#1A3B5C`, цифра `emerald-300` |

Скругления: `rounded-xl` — плитки и карточки списка; `rounded-2xl` — секции;
`rounded-lg` — кнопки и поля; `rounded-md` — инпуты в модалках; `rounded-full` —
чипы. Тени: `shadow-sm` по умолчанию, `shadow-md` — тёмная плитка и наведение на
карточку-ссылку.

---

## 5. Плитка-итог

```html
<div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
  <div class="text-[11px] uppercase tracking-wide text-slate-400">Приход за день</div>
  <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-emerald-600">+120 000 ₸</div>
  <div class="mt-0.5 text-[11px] text-slate-400">пояснение</div>
</div>
```

| Тон | Рамка и фон | Цифра | Подпись |
|---|---|---|---|
| обычный | `border-slate-200 bg-white` | `text-slate-800` | `text-slate-400` |
| приход | `border-slate-200 bg-white` | `text-emerald-600` | `text-slate-400` |
| расход | `border-rose-200 bg-rose-50` | `text-rose-600` | `text-rose-500` |
| ожидание | `border-amber-200 bg-amber-50` | `text-amber-700` | `text-amber-600` |
| итог | `style="background-color:#1A3B5C"` | `text-emerald-300` | `text-white/60` |

---

## 6. Секция-карточка

```html
<div class="mt-6 rounded-2xl border border-slate-200 bg-white shadow-sm">
  <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
    <h3 class="text-sm font-semibold text-slate-900">Название</h3>
    <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium tabular-nums text-emerald-700">168 925 284 ₸</span>
  </div>
  <!-- содержимое -->
</div>
```

---

## 7. Таблица

```html
<div class="overflow-x-auto">
  <table class="min-w-full text-sm">
    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
      <tr>
        <th class="px-6 py-2.5">Дата</th>
        <th class="px-4 py-2.5 text-right">Сумма</th>
        <th class="px-4 py-2.5 text-right">Действия</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-slate-50">
      <tr class="group transition-colors duration-150 hover:bg-slate-50/60">
        <td class="px-6 py-2.5 text-slate-500">16 августа 2026 г.</td>
        <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-800">44 141 ₸</td>
        <td class="whitespace-nowrap px-4 py-2.5 text-right">
          <button class="rounded p-1 text-slate-300 transition-colors hover:text-indigo-600">✎</button>
          <button class="rounded p-1 text-slate-300 transition-colors hover:text-rose-600">🗑</button>
        </td>
      </tr>
    </tbody>
    <tfoot class="border-t border-slate-200 bg-slate-50 text-sm font-semibold">
      <tr><td class="px-6 py-3 text-slate-500" colspan="2">Итого</td><td class="px-4 py-3 text-right tabular-nums text-slate-900">…</td></tr>
    </tfoot>
  </table>
</div>
```

Дополнительные ссылки в строке проявляются по наведению:
`opacity-0 transition-opacity group-hover:opacity-100`.

---

## 8. Карточка списка

```html
<div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
  <div class="flex flex-wrap items-start justify-between gap-2">
    <div class="min-w-0">
      <div class="text-xl font-bold tabular-nums text-slate-900">795 950 ₸</div>
      <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-400">
        <span>20 июля 2026 г.</span>
        <span class="rounded-full bg-slate-100 px-2.5 py-0.5 font-medium text-slate-500">Категория</span>
        <a class="font-medium text-indigo-600 hover:underline">QT-001 · Заказчик</a>
      </div>
    </div>
    <div class="text-right text-[11px] text-slate-400">подал <span class="font-medium text-slate-600">Имя</span></div>
  </div>

  <p class="mt-2 text-sm text-slate-600">описание</p>
  <img class="mt-2 max-h-44 w-full rounded-lg border border-slate-100 object-contain" />

  <div class="mt-3 flex items-center justify-between gap-2">
    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700">пометка</span>
    <button class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white transition-colors duration-150 hover:bg-indigo-700">Действие</button>
  </div>
</div>
```

Картинка внутри карточки — не выше `max-h-44`. Если данных нет — короткая
пометка-чип, не пустая рамка.

---

## 9. Чипы

База: `rounded-full px-2.5 py-0.5 text-xs font-medium` (мелкий вариант —
`px-2 py-0.5 text-[11px]`).

| Смысл | Классы |
|---|---|
| Ожидание | `bg-amber-50 text-amber-700` |
| Успех, приход | `bg-emerald-50 text-emerald-700` |
| Отметка, аванс | `bg-indigo-50 text-indigo-700` |
| Расход, долг | `bg-rose-50 text-rose-600` |
| Счёт, банк, филиал | `bg-sky-50 text-sky-700` |
| Нейтральное | `bg-slate-100 text-slate-500` |

Чип с индикатором: внутрь — `<span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>`,
сам чип `inline-flex items-center gap-1.5`.

---

## 10. Кнопки

| Роль | Классы |
|---|---|
| Основная | `rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition-colors duration-150 hover:bg-indigo-700` |
| Основная компактная | то же с `px-3 py-1.5 text-xs` |
| Вторичная | `rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50` |
| Мягкая зелёная | `rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-100` |
| Мягкая красная | `rounded-lg bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-600 hover:bg-rose-100` |
| Иконка в строке | `rounded p-1 text-slate-300 hover:text-indigo-600` (удаление — `hover:text-rose-600`) |
| Сегмент-контрол | обёртка `inline-flex rounded-lg border border-slate-200 bg-white p-0.5`; сегмент `rounded-md px-3 py-1.5 text-xs font-semibold`; активный `bg-indigo-600 text-white`, обычный `text-slate-500 hover:bg-slate-50` |
| Чип-фильтр | `rounded-full border px-3 py-1 text-xs font-medium`; активный `border-indigo-500 bg-indigo-50 text-indigo-700`; обычный `border-slate-200 bg-white text-slate-500 hover:bg-slate-50` |
| Отключённая | добавить `disabled:opacity-50` |

---

## 11. Поля и модалки

```html
<div class="p-6">
  <h2 class="mb-1 text-lg font-semibold text-slate-900">Заголовок</h2>
  <p class="mb-4 text-xs text-slate-400">Одна фраза-пояснение.</p>

  <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2">
      <label class="mb-1 block text-xs font-medium text-slate-500">Поле *</label>
      <input class="w-full rounded-md border-slate-300 text-sm shadow-sm" />
      <div class="mt-1 text-xs text-red-600">текст ошибки</div>
    </div>
  </div>

  <div class="mt-6 flex justify-end gap-2">
    <button class="secondary">Отмена</button>
    <button class="primary">Сохранить</button>
  </div>
</div>
```

Фокус полей вне модалок: `focus:border-indigo-400 focus:ring-2 focus:ring-indigo-500/20`.
Файловый инпут: `file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-slate-600`.
Ширина модалки — `max-width="lg"` для форм, `md` — для списков.

---

## 12. Пустые состояния

```html
<div class="px-6 py-10 text-center text-sm text-slate-400">Заявок пока нет — нажмите «+ Заявка».</div>
```

Вариант отдельным блоком: `rounded-xl border border-slate-200 bg-white px-6 py-8
text-center text-sm text-slate-400 shadow-sm`; для «ничего не найдено» —
`border-dashed border-slate-300`.

---

## 13. Анимации

- Только `transform` и `opacity`.
- Ховеры: `transition-colors duration-150`; карточки-ссылки —
  `transition-shadow hover:shadow-md`; строчные действия — `transition-opacity`.
- Раскрытие блоков: `<Transition>` с `enter-active-class="transition duration-200 ease-out"`,
  `enter-from-class="opacity-0 -translate-y-1"`, `leave-active-class="transition duration-150 ease-in"`,
  `leave-to-class="opacity-0 -translate-y-1"`.
- Уход элемента из списка: `<TransitionGroup>`, `opacity` + `translateY(-6px)`, 250 мс.
- Стрелка раскрытия: `transition-transform duration-200` + `rotate-90`.
- `@media (prefers-reduced-motion: reduce)` — переходы отключаются.
- Счётчики и таблицы не анимируются; вечных анимаций нет.

---

## 14. Адаптив

| Блок | Поведение |
|---|---|
| Плитки | 1 → `sm:3` → `lg:4` |
| Карточки списка | 1 → `md:2` → `xl:3` |
| Таблицы | всегда в `overflow-x-auto`; суммы, даты, действия — `whitespace-nowrap` |
| Вкладки, чипы-фильтры | `overflow-x-auto` / `flex-wrap` |
| Шапка страницы | `flex-wrap` — действия переносятся под заголовок |
| Меню | на телефоне слайд-овер поверх контента |

---

## 15. Печать

Общие классы: `.no-print` — скрыть при печати, `.print-only` — показать только
на бумаге (`display:none` на экране).

```css
@media print {
  aside, header, .no-print { display: none !important; }
  .print-only { display: block; }
  main { padding: 0 !important; }
  body { background: #fff; }
  table { font-size: 11pt; }
}
```

Печатная шапка: название документа `text-lg font-bold`, под ним дата и режим
`text-sm`. Подписи внизу — `grid grid-cols-2 gap-8 text-sm` со строками вида
`Кассир ______________________`.
