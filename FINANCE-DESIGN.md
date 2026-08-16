# Дизайн денежных страниц QAZAQ TAS ERP — описание

> Как выглядят и ведут себя страницы раздела «Финансы» (Обзор, Счета,
> Поступления, Расходы, Касса, Задолженности, Мои расходы, Зарплата).
> Документ самодостаточный: классы указаны точно, по нему страницу можно
> собрать заново. Стек: Vue 3 + Inertia + Tailwind.

---

## 1. Каркас страницы

Все денежные страницы обёрнуты в `FinanceLayout` — без исключений. Он даёт три
вещи, которых страницы не делают сами: вкладки, заголовок и контейнер.

```
AppLayout (боковое меню + верхняя панель)
└── FinanceLayout
    ├── ряд вкладок раздела
    ├── заголовок + пояснение + слот «действия»
    └── содержимое страницы
```

**Контейнер:** `mx-auto max-w-7xl` для широких страниц (таблицы, борд, книга),
`mx-auto max-w-5xl` для личных (Мои расходы, Задолженности). Внутренние отступы
даёт `AppLayout` (`p-4 sm:p-6`).

**Вкладки:**

```html
<nav class="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200 pb-px">
  <Link class="whitespace-nowrap rounded-t-lg px-3 py-2 text-sm font-medium
               transition-colors duration-150"
        :class="active
          ? 'border-b-2 border-indigo-600 text-indigo-700'
          : 'border-b-2 border-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-700'">
```

Горизонтальная прокрутка на телефоне — длинный ряд не ломает страницу. Вкладки
фильтруются по правам: сотрудник видит только «Мои расходы» и «Зарплату».

**Заголовок:**

```html
<h2 class="text-lg font-semibold text-slate-900">Касса</h2>
<p class="mt-0.5 text-xs text-slate-400">кассовая книга за день: начало → операции → конец</p>
```

Пояснение — одна строка строчными буквами, объясняет назначение страницы, а не
повторяет заголовок.

**Слот «действия»** (`<template #actions>`) — справа от заголовка, в одну линию:
фильтр месяца, переключатели, кнопки создания. У всех страниц кнопки в одном
месте, а не в своём углу у каждой.

---

## 2. Плитка-итог (`FinanceTile`)

Одна плитка на все страницы. Пять тонов по смыслу цифры.

```html
<div class="rounded-xl p-4 shadow-sm border border-slate-200 bg-white">
  <div class="text-[11px] uppercase tracking-wide text-slate-400">Приход за день</div>
  <div class="mt-1 whitespace-nowrap text-xl font-bold tabular-nums text-emerald-600">+120 000 ₸</div>
  <div class="mt-0.5 text-[11px] text-slate-400">пояснение</div>
</div>
```

| Тон | Когда | Рамка и фон | Цифра |
|---|---|---|---|
| `default` | нейтральная сумма | `border-slate-200 bg-white` | `text-slate-800` |
| `good` | приход, оплачено | `border-slate-200 bg-white` | `text-emerald-600` |
| `bad` | расход, долг | `border-rose-200 bg-rose-50` | `text-rose-600` |
| `warn` | ждёт действия | `border-amber-200 bg-amber-50` | `text-amber-700` |
| `dark` | итог раздела | `#1A3B5C` (фирменный тёмно-синий) | `text-emerald-300` |

Правила: цифра всегда `tabular-nums` (колонки не пляшут), подпись — `[11px]`
капсом, сетка `grid gap-3` с `sm:grid-cols-3` или `lg:grid-cols-4`.
Счётчики не анимируются.

---

## 3. Карточка-блок (секция страницы)

```html
<div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
  <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-4">
    <h3 class="text-sm font-semibold text-slate-900">Оплаченные за месяц</h3>
    <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700 tabular-nums">168 925 284 ₸</span>
  </div>
  …содержимое…
</div>
```

Между секциями — `mt-6`. Шапка блока всегда несёт название и итог/счётчик:
цифра рядом с названием избавляет от прокрутки ради суммы.

---

## 4. Таблицы

```html
<table class="min-w-full text-sm">
  <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-400">
    <tr><th class="px-6 py-2.5">Дата</th><th class="px-4 py-2.5 text-right">Сумма</th></tr>
  </thead>
  <tbody class="divide-y divide-slate-50">
    <tr class="group transition-colors duration-150 hover:bg-slate-50/60">
      <td class="px-6 py-2.5 text-slate-500">16 августа 2026 г.</td>
      <td class="px-4 py-2.5 text-right font-semibold tabular-nums text-slate-800">44 141 ₸</td>
    </tr>
  </tbody>
</table>
```

- Обёртка `overflow-x-auto` — таблица не ломает страницу на телефоне.
- Первая колонка `px-6`, остальные `px-4`.
- Деньги — вправо, `tabular-nums`, суммы жирные, даты и подписи `text-slate-500`.
- Строчные действия (✎ / 🗑) появляются по наведению:
  `opacity-0 transition-opacity group-hover:opacity-100`.
- Итоговая строка — `tfoot` с `bg-slate-50` и `font-semibold`.

---

## 5. Карточки очереди (страница «Расходы»)

Компактность здесь важнее украшений: бухгалтер смотрит десятки заявок подряд.

```html
<div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
  <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
    <div class="text-xl font-bold tabular-nums text-slate-900">795 950 ₸</div>
    <div class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px] text-slate-400">
      дата · категория-чип · ссылка на сделку
    </div>
    <p class="mt-2 text-sm text-slate-600">за что</p>
    <img class="mt-2 max-h-44 w-full rounded-lg border border-slate-100 object-contain" />
    <div class="mt-3 flex items-center justify-between gap-2">
      <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700">без чека</span>
      <button class="rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white">Проверил, оплатить</button>
    </div>
  </div>
</div>
```

Правила, добытые практикой:
- **Чек открыт сразу**, но не выше `max-h-44` — иначе один длинный снимок
  растягивает карточку на экран.
- **Отсутствие чека — пометка, а не рамка.** Пустая рамка размером с чек
  занимает место ради того, чего нет.
- Кнопка действия и пометка о чеке — в одной нижней строке.
- Уход карточки из очереди — `<TransitionGroup>`, `opacity` + `translateY(-6px)`,
  250 мс; список не «прыгает».

---

## 6. Чипы-статусы

`rounded-full px-2.5 py-0.5 text-xs font-medium` + мягкий фон. Цвет — по смыслу,
не по вкусу:

| Смысл | Классы |
|---|---|
| Ждёт бухгалтера / без чека | `bg-amber-50 text-amber-700` |
| Оплачен, приход, активен | `bg-emerald-50 text-emerald-700` |
| Аванс, расход компании | `bg-indigo-50 text-indigo-700` |
| Долг, расход, просрочка | `bg-rose-50 text-rose-600` |
| Счёт, банк | `bg-sky-50 text-sky-700` |
| Категория, нейтральное | `bg-slate-100 text-slate-500` |

Статус с точкой (как «● Активен»): внутрь чипа `<span class="h-1.5 w-1.5 rounded-full bg-emerald-500">`.

---

## 7. Кнопки

| Роль | Классы |
|---|---|
| Основная | `rounded-lg bg-indigo-600 px-3..4 py-1.5..2 text-xs/sm font-semibold text-white hover:bg-indigo-700` |
| Вторичная | `rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50` |
| Действие в строке | `rounded p-1 text-slate-300 hover:text-indigo-600` (удаление — `hover:text-rose-600`) |
| Мягкая в таблице | `bg-emerald-50 text-emerald-700 hover:bg-emerald-100` / `bg-rose-50 text-rose-600 hover:bg-rose-100` |
| Сегмент-контрол | обёртка `inline-flex rounded-lg border border-slate-200 bg-white p-0.5`, активный сегмент `bg-indigo-600 text-white` |
| Чип-фильтр | `rounded-full border px-3 py-1 text-xs font-medium`, активный `border-indigo-500 bg-indigo-50 text-indigo-700` |

У всех — `transition-colors duration-150`. Витринные классы `btn-*` в ERP не
используются.

---

## 8. Формы и модалки

- Только существующий `Components/Modal.vue` (анимация внутри),
  подтверждения — `confirmDialog`, никаких `window.confirm`.
- Внутри: `<div class="p-6">`, заголовок `text-lg font-semibold text-slate-900`,
  под ним пояснение `text-xs text-slate-400` — одной фразой объясняет
  последствие («Счёт бухгалтеру на оплату: он проверит и оплатит»).
- Поля: подпись `mb-1 block text-xs font-medium text-slate-500`, инпут
  `w-full rounded-md border-slate-300 text-sm shadow-sm`, ошибка
  `mt-1 text-xs text-red-600`. Сетка `grid grid-cols-1 gap-4 sm:grid-cols-2`,
  широкие поля — `sm:col-span-2`.
- Кнопки снизу справа: `SecondaryButton` (Отмена) + `PrimaryButton`.
- Денежные формы показывают остаток: «Доступно: касса … · счёт …», и при
  превышении подпись краснеет — но отправку не блокирует.
- Формы, живущие на двух страницах, вынесены в компоненты
  (`CompanyExpenseModal`, `ExpenseCategoriesModal`) — одна форма денег, а не две
  расходящиеся копии.

---

## 9. Пустые состояния

Никогда не пустой блок: `px-6 py-8..10 text-center text-sm text-slate-400` и
фраза, которая говорит, что делать: «Очередь пуста ✓», «Заявок пока нет —
нажмите „+ Заявка"», «В этот день денег не двигали.», «Никого не нашли —
измените поиск или отборы».

---

## 10. Движение и печать

- Только `transform` и `opacity`; раскладку не анимируем.
- Микро-переходы: `transition-colors duration-150` на hover,
  `transition-shadow` на карточках-ссылках, `transition-opacity` на действиях.
- Раскрытия — `<Transition>` с `enter-from-class="opacity-0 -translate-y-1"`,
  200–300 мс; `prefers-reduced-motion` уважается там, где дольше 300 мс.
- Печать (кассовая книга): классы `.no-print` и `.print-only` — общее правило
  для всей ERP. В `@media print` скрываются `aside`, `header`, `.no-print`,
  у `main` снимается padding, появляется шапка «Отчёт кассира» и строки
  подписей.

---

## 11. Адаптив

- Плитки: `grid-cols-1` → `sm:grid-cols-3` → `lg:grid-cols-4`.
- Карточки очереди: 1 → `md:2` → `xl:3`.
- Таблицы — всегда в `overflow-x-auto`, ячейки `whitespace-nowrap` там, где
  перенос ломает смысл (суммы, даты, действия).
- Вкладки и фильтр-чипы — `overflow-x-auto` / `flex-wrap`.
- Боковое меню на телефоне — слайд-овер (тот же список, что на десктопе).

---

## 12. Типографика и палитра

- Заголовок страницы — `text-lg font-semibold text-slate-900`; секции —
  `text-sm font-semibold text-slate-900`; подписи — `text-xs`/`text-[11px]`
  `text-slate-400`.
- Деньги — `tabular-nums`, формат `Intl.NumberFormat('ru-RU')` + « ₸».
- Индиго `indigo-600` — действие и активное состояние.
  Изумруд `emerald` — приход и «хорошо». Роза `rose` — расход, долг, опасность.
  Янтарь `amber` — ожидание. Небо `sky` — банк и счета.
  Сланец `slate` — текст и рамки. `#1A3B5C` — фирменный тёмный итог.
- Скругления: плитки и карточки очереди `rounded-xl`, секции `rounded-2xl`,
  кнопки и поля `rounded-lg` / `rounded-md`, чипы `rounded-full`.
- Тени: `shadow-sm` по умолчанию, `shadow-md` — только у тёмной итоговой плитки
  и при наведении на карточку-ссылку.

---

## 13. Чего на этих страницах не делают

- Не рисуют плитки и шапки вручную — берут `FinanceTile` и `FinanceLayout`.
- Не считают суммы в браузере: цифра приходит с сервера посчитанной.
- Не анимируют таблицы и счётчики, не добавляют вечных анимаций.
- Не показывают пустую рамку вместо отсутствующих данных.
- Не заводят вторую форму для того же действия на другой странице.
- Не оставляют строку интерфейса без `$e()` и перевода в `lang/kk/erp.php` —
  сторожевой тест не пропустит.
