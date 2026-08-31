# Дизайн-система QAZAQ TAS — переносимый справочник

> Самодостаточный файл: скопируйте его в другой проект — здесь все токены,
> CSS-слои, словарь тёмной темы и правила, по которым построен интерфейс.
> Стек: Tailwind CSS + Vue 3 (подойдёт любой фреймворк — суть в классах и CSS).

Система состоит из двух независимых «миров»:

| Мир | Стиль | Темы |
|---|---|---|
| **ERP / SaaS-приложение** | Modern SaaS: Soft UI + матовое стекло | светлая (основная) + тёмная slate |
| **Витрина / маркетинг** | «Камень»: тёмный бетон + песочный акцент | тёмная (основная) + светлая |

---

## 1. Палитры

### ERP (на шкале slate из Tailwind)
- Полотно: светлое `#F6F7FB`, тёмное `#0B0F17`.
- Карточки: `bg-white` / `dark:bg-slate-900/70`.
- Текст: `text-slate-900 dark:text-slate-100` (заголовки), `text-slate-500 dark:text-slate-400` (подписи), `text-slate-400` — читается в обеих темах, dark-вариант не нужен.
- Акцент: **indigo** (`#4F46E5` действия, `#A5B4FC` в тёмной).
- Семантика: emerald = деньги/успех, rose = долг/ошибка, amber = внимание, sky = информация.
- Пастельные подложки смысла: `#EEF0FE` accent · `#E8F6F0` good · `#FDF3E3` warn · `#FDECEF` bad · `#F1F4F9` calm.

### Витрина (CSS-переменные, тема переключается атрибутом data-theme)
```css
/* тёмная (основная) */
--ink-900: 24 24 27;   /* фон-бетон */    --sand-300: 200 183 154; /* песочный акцент */
--sand-50: 250 249 246; /* почти белый текст */
/* светлая тема просто переопределяет те же переменные */
```
Классы вида `bg-ink-800`, `text-sand-50` объявлены в tailwind.config через
`rgb(var(--ink-800) / <alpha-value>)` — вся вёрстка следует за темой без dark:-классов.

---

## 2. Tailwind config (фрагмент для копирования)

```js
export default {
    darkMode: 'class',
    theme: { extend: {
        fontFamily: { sans: ['Plus Jakarta Sans', 'Figtree', ...defaultTheme.fontFamily.sans] },
        colors: { primary: colors.indigo, success: colors.emerald, danger: colors.rose, warning: colors.amber },
        boxShadow: {
            soft:    '0 1px 2px rgba(15,23,42,.04), 0 1px 3px rgba(15,23,42,.05)',
            'soft-md': '0 1px 2px rgba(15,23,42,.04), 0 8px 20px -12px rgba(15,23,42,.18)',
            'soft-lg': '0 2px 4px rgba(15,23,42,.04), 0 18px 40px -20px rgba(15,23,42,.24)',
        },
        letterSpacing: { display: '-0.035em' },
        transitionTimingFunction: { premium: 'cubic-bezier(0.16, 1, 0.3, 1)' },
    }},
    plugins: [require('@tailwindcss/forms')],
};
```
Тени НАМЕРЕННО ниже дизайн-дриблов: на плотных таблицах глубокая тень читается как грязь.

---

## 3. Механика двух тем (ERP)

1. `darkMode: 'class'` — класс `dark` живёт на `<html>`.
2. **До первой отрисовки** (иначе тёмная мигает белым) — инлайн-скрипт в blade/index.html
   (при строгом CSP подпишите nonce):
```html
<script>try{if(localStorage.getItem('erp.theme')==='dark')document.documentElement.classList.add('dark')}catch(e){}</script>
```
3. Переключатель — composable:
```js
const dark = ref(document.documentElement.classList.contains('dark'));
const toggle = () => {
    dark.value = !dark.value;
    document.documentElement.classList.toggle('dark', dark.value);
    try { localStorage.setItem('erp.theme', dark.value ? 'dark' : 'light'); } catch {}
};
```
4. `html.dark { color-scheme: dark; }` — нативные скроллбары/календари темнеют сами.
5. **Формы красятся одним CSS-правилом**, а не dark:-классами на каждом инпуте
   (специфичность `.dark input` выше голой утилиты — побеждает всегда):
```css
.dark input:not([type='checkbox']):not([type='radio']), .dark select, .dark textarea {
    background-color: rgb(30 41 59 / .6); border-color: #334155; color: #F1F5F9;
}
.dark input::placeholder, .dark textarea::placeholder { color: #64748B; }
.dark select option { background-color: #1E293B; color: #F1F5F9; }
```

### Словарь dark-соответствий (добавлять РЯДОМ, светлое не менять)
| Светлое | + тёмное |
|---|---|
| `bg-white`, `bg-white/70..95` | `dark:bg-slate-900/70` |
| `bg-slate-50` / `-100` / `-200` | `dark:bg-slate-800/50` / `-800/60` / `-700` |
| `text-slate-900/-800/-700..600/-500` | `dark:text-slate-100/-200/-300/-400` |
| `text-slate-400` | без изменений (читается в обеих) |
| `border-slate-100` / `-200` / `-300` | `dark:border-slate-800` / `-800/80` / `-600` |
| `divide-slate-100\|50` | `dark:divide-slate-800` |
| `bg-{цвет}-50` / `-100` (бейджи) | `dark:bg-{цвет}-500/10` / `-500/20` |
| `text-{цвет}-600` / `-700` | `dark:text-{цвет}-400` / `-300` |
| `hover:bg-slate-50\|100` | `dark:hover:bg-slate-800/60` |
| чёрные кнопки `bg-slate-900 text-white` | инверсия: `dark:bg-slate-100 dark:text-slate-900` |
| цветные кнопки (`bg-indigo-600 text-white`) | без изменений |
| градиенты панелей `from-*-50 ...` | `dark:from-slate-900/85 dark:via-slate-900/70 dark:to-slate-900/60` |

---

## 4. Поверхности (Soft UI + стекло)

**Правило материала**: стеклом делается только то, что ПЛАВАЕТ над содержимым
(шапка, сайдбар, модалки, выпадающие панели). Таблицы и карточки с цифрами —
непрозрачные: на них смотрят весь день.

```css
/* Полотно с цветным подсветом — ради него размытие вообще читается */
.app-canvas { position: relative; background: #F6F7FB; isolation: isolate; }
.app-canvas::before { content:''; position: fixed; inset: 0; z-index: -1; pointer-events: none;
    background: radial-gradient(52rem 34rem at 6% -10%, rgba(99,102,241,.12), transparent 62%),
                radial-gradient(44rem 30rem at 98% 100%, rgba(16,185,129,.10), transparent 64%); }
.dark .app-canvas { background: #0B0F17; }

/* Стекло */
.glass { background: rgba(250,251,255,.78); backdrop-filter: saturate(180%) blur(16px); }
.dark .glass { background: rgba(11,15,23,.78); }
/* Всегда давайте непрозрачный запасной фон: */
@supports not (backdrop-filter: blur(1px)) { .glass { background: #FFF; } .dark .glass { background:#0F172A; } }

/* Активный пункт меню/вкладка = ПРИПОДНЯТАЯ карточка, а не заливка цветом:
   «где я» считывается формой. Один приём на меню и вкладки — не два языка. */
.nav-current { background: #FFF; font-weight: 600;
    box-shadow: 0 1px 2px rgba(15,23,42,.04), 0 6px 16px -10px rgba(15,23,42,.18); }
.dark .nav-current { background: #1E293B; box-shadow: 0 1px 2px rgba(0,0,0,.4), 0 6px 16px -10px rgba(0,0,0,.6); }

/* Капсула-фильтр */
.chip { display:inline-flex; align-items:center; gap:.375rem; border-radius:9999px;
    padding:.3125rem .75rem; font-size:.8125rem; font-weight:600; white-space:nowrap; }
```

**Сетка/бенто**: карточки `rounded-2xl` (внутренние) и `rounded-3xl` (крупные
контейнеры), отступы `p-5`/`p-6`/`p-8`, интервалы `gap-3`(плитки)/`gap-6`(блоки).

---

## 5. Типографика

- **Только rem-шкала Tailwind** (`text-xs`…`text-3xl`). Пиксельных размеров
  (`text-[11px]`) не бывает: размер всего интерфейса меняется одной настройкой —
  атрибутом на корне: `html[data-ui-font="compact"]{font-size:14px}` … `"xlarge"{19px}`.
- Заголовки-цифры: `font-bold tabular-nums`, дисплейные — `tracking-display`.
- **Запрет разлома значений**: короткие данные (размеры, цены, коды, даты) —
  `whitespace-nowrap`; длинные фразы — максимум 2 строки
  (`display:-webkit-box; -webkit-line-clamp:2; overflow:hidden`);
  переносить слова только целиком: `overflow-wrap: break-word` (НЕ `anywhere`).
- Подписи-«ярлыки»: `text-xs uppercase tracking-wide text-slate-400`.

---

## 6. Микровзаимодействия

- Easing на всё «премиальное»: `cubic-bezier(0.16, 1, 0.3, 1)`, длительности 150–300 мс.
- Подъём карточки-ссылки:
```css
.hover-lift { transition: transform .3s cubic-bezier(.16,1,.3,1), box-shadow .3s cubic-bezier(.16,1,.3,1); }
.hover-lift:hover { transform: translateY(-.25rem); box-shadow: var(--тень-3-ступени); }
@media (prefers-reduced-motion: reduce) { .hover-lift, .hover-lift:hover { transition:none; transform:none; } }
```
- Движется только `transform` и `opacity` — ничего, что вызывает reflow.
- Появление блоков «лесенкой»: один keyframe + `animation-delay` из CSS-переменной `--d`.
- `prefers-reduced-motion` уважается ВЕЗДЕ (анимации → простой fade, blur снимается).

---

## 7. Выноски на изображениях товара (hotspots)

- Плашка: стекло `rounded-2xl` + blur, значение `font-semibold` (короткое —
  `nowrap`, длинное — 2 строки, `max-width: 13.75rem`), подпись капсом `text-[11px]`.
- Точка: 6px + свечение-кольцо `box-shadow: 0 0 0 4px rgb(акцент / .14)`.
- Линия: 1px градиент от прозрачного (у плашки) к акценту (у точки).
- **Точка сидит НА предмете**: по альфа-каналу вырезанного PNG считается силуэт
  (canvas → per-row min/max непрозрачных пикселей), и точка ставится на кромку
  на нужной высоте. Плашка не сжимается — при нехватке места точка уходит чуть
  глубже на предмет, а не текст в столбик. Запасные позиции — фиксированные CSS-проценты.

---

## 8. Страницы входа

Split-screen: слева брендовая сцена (градиент бренд-цвета, «дышащие» blob'ы,
частицы, сетка 44px прозрачностью 7%), справа белая форма. Кнопки —
градиент `from-emerald-500 to-emerald-600`, поля с иконкой слева (`pl-11`).
Ввод кода — 6 отдельных ячеек `h-14 w-12 text-2xl font-bold` с автопереходом.

---

## 9. Чек-лист переноса в новый проект

1. Скопировать фрагмент tailwind.config (§2) + шрифты Plus Jakarta Sans/Figtree.
2. Завести CSS-слои: канва+стекло (§4), формы тёмной темы (§3.5), hover-lift (§6).
3. Вставить FOUC-скрипт и composable темы (§3), кнопку ☀/🌙 в шапку.
4. Писать светлую вёрстку как обычно; тёмную добавлять ПО СЛОВАРЮ (§3) —
   рядом, не заменяя. Инпуты dark-классами не трогать — их красит CSS.
5. Соблюдать §5 (rem-шкала, nowrap) и §6 (движение только transform/opacity).
6. Проверять обе темы + `prefers-reduced-motion` перед выпуском.

> Происхождение: ERP/CRM + витрина qazaqtas.kz, состояние на 31.08.2026.
> Живые исходники слоёв: resources/css/{glass,soft,tokens,site,hero}.css,
> tailwind.config.js, resources/js/composables/useErpTheme.js.
