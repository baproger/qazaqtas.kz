# План: ИИ-помощник внутри ERP

> Статус: план утверждён владельцем, реализация — моделью Opus.
> Доступ: **только роли `admin` и `director`** (обе уже существуют в RolePermissionSeeder).
> Модель: **claude-opus-5** через официальный PHP SDK `anthropic-ai/sdk` (уже в composer.json,
> используется в `app/Services/SeoAiService.php` — брать его как образец работы с клиентом).

---

## 1. Что строим

Чат-помощник внутри ERP: руководитель задаёт вопрос («сколько сделок зависло на Акте?»,
«что по складу в Таразе?», «составь текст письма поставщику») — помощник отвечает,
зная контекст компании (агрегаты из БД). История диалогов сохраняется.

Не строим: доступ для других ролей, автодействия в системе (помощник только читает
данные и отвечает текстом), витринную версию.

## 2. Доступ и безопасность

- Gate `use-ai-assistant`: `$user->hasAnyRole(['admin', 'director'])`.
  Регистрация в `AppServiceProvider::boot()` (там уже есть Gate::before для admin).
- Маршруты под `auth` + `can:use-ai-assistant` + `throttle:15,1`.
- Пункт меню в сайдбаре `AppLayout.vue` показывается только при праве
  (передавать флаг через `HandleInertiaRequests::share` → `auth.canUseAi`).
- Ключ `ANTHROPIC_API_KEY` живёт только на сервере (уже так). Ответы ходят через
  backend — CSP и ключ не затрагиваются.
- В system prompt отправляем **агрегаты** (суммы, счётчики, этапы), а не полные
  карточки клиентов: телефоны/ИИН клиентов в промпт не включать.
- Дневной лимит: `ai_daily_limit` запросов на пользователя (config, по умолчанию 100),
  проверка в контроллере, при превышении — вежливый отказ.
- Аудит: каждый запрос пишется в таблицу сообщений (см. схему) — само по себе аудит-лог.

## 3. Схема БД (миграция)

```php
ai_conversations: id, user_id (FK, cascadeOnDelete), title (string, из первого
    вопроса, обрезка 80), created_at, updated_at
ai_messages: id, conversation_id (FK, cascadeOnDelete), role (enum: user|assistant),
    content (text), input_tokens (int, null), output_tokens (int, null), created_at
```

Индексы: `ai_conversations.user_id`, `ai_messages.conversation_id`.
Диалоги приватны: пользователь видит только свои (scope по user_id в контроллере).

## 4. Backend

### 4.1 `app/Services/AiAssistantService.php`

- Конструктор как в `SeoAiService`: `new \Anthropic\Client(apiKey: config('services.anthropic.key'))`.
- Модель: `config('services.anthropic.assistant_model')` → env `AI_ASSISTANT_MODEL`,
  default `claude-opus-5` (добавить в `config/services.php` рядом с `model`).
- Метод `answer(Conversation $c, string $question): array` — собирает:
  - **system prompt**: кто такой помощник (ERP QAZAQ TAS, мраморный композит,
    3 площадки), правила (отвечай кратко, по-русски или по-казахски — на языке
    вопроса; если данных нет — скажи честно) + **контекст-агрегаты** (см. 4.2);
  - историю диалога (последние 20 сообщений из БД, roles user/assistant);
  - вызов: `messages->create(model, maxTokens: 4096, thinking: ['type' => 'adaptive'],
    system: [...], messages: [...])`. Из ответа брать только text-блоки
    (guard по `$block->type === 'text'` — при adaptive thinking первым может идти
    thinking-блок). Сохранять usage-токены в ai_messages.
- Ошибки: `APIStatusException` → по `$e->type`: rate_limit/overloaded → «Помощник
  перегружен, попробуйте через минуту», прочее → лог + общее сообщение.
  Нет ключа → «ИИ-помощник не настроен: добавьте ANTHROPIC_API_KEY» (без фолбэка,
  в отличие от SEO — здесь шаблонный ответ бессмыслен).

### 4.2 Контекст данных (метод `buildContext(User $u): string`)

Собирается на каждый запрос (кэш 5 минут через `Cache::remember`):

- Сделки: количество и сумма по этапам текущей воронки, просроченные (overdue_count).
- Цех: заказы по этапам, сколько «висит» дольше 3 дней.
- Склад: топ-10 позиций по остаткам + позиции с остатком ниже минимального.
- Задачи: открытые/просроченные по ответственным (только счётчики и имена).
- Продажи: выручка текущего месяца vs прошлого (из существующих отчётов).

Формат — компактный текст/markdown-таблицы. Использовать существующие модели
(Deal, Project, StockService, Task) и уже написанные выборки из Reports-контроллеров.

**Фаза 3 (после MVP):** заменить статический контекст на tool use
(`$client->beta->messages->toolRunner()` из SDK) с whitelisted-инструментами
`get_deals_summary`, `get_stock`, `get_overdue_tasks` — модель сама решает,
какие данные запросить. До этого не усложнять.

### 4.3 `app/Http/Controllers/AiAssistantController.php`

- `index()` — Inertia-страница со списком диалогов пользователя.
- `show(Conversation)` — сообщения диалога (авторизация: свой диалог).
- `send(Request)` — validate: `message` required|string|max:4000,
  `conversation_id` nullable|exists. Нет id → создать диалог с title из вопроса.
  Синхронный ответ в MVP (Inertia partial reload). SSE-стриминг — фаза 2.
- `destroy(Conversation)` — удалить свой диалог.

### 4.4 Маршруты (`routes/web.php`)

```php
Route::middleware(['auth', 'can:use-ai-assistant'])->prefix('ai')->group(function () {
    Route::get('/', [AiAssistantController::class, 'index'])->name('ai.index');
    Route::get('/{conversation}', ...)->name('ai.show');
    Route::post('/send', ...)->middleware('throttle:15,1')->name('ai.send');
    Route::delete('/{conversation}', ...)->name('ai.destroy');
});
```

## 5. Frontend (`resources/js/Pages/Ai/Index.vue`)

- Раскладка: слева список диалогов (+ «Новый диалог», удаление), справа чат.
- Сообщения: пользователь справа (индиго), помощник слева (стеклянная карточка
  `.spotlight`); ответы рендерить как markdown (существующая зависимость или
  лёгкий парсер: жирный/списки/таблицы/код).
- Ввод: textarea с автовысотой, Enter — отправить, Shift+Enter — перенос.
  Пока ждём ответ: кнопка заблокирована, индикатор «Помощник думает…»
  (три пульсирующие точки; никакой анимации дольше необходимого).
- Пустое состояние: градиентная панель в стиле CtaEstimate с 3–4 кнопками-примерами
  вопросов («Что по просроченным сделкам?», «Остатки по складу», …).
- Дизайн: правила ERP (rem-шкала Tailwind, тёмная тема через `dark:`, glow-эффекты
  `.spotlight` уже глобально подключены в AppLayout).
- Все строки интерфейса через `$e()` + переводы в `lang/kk/erp.php`
  (СТРОГИЙ тест ErpInterfaceLocaleTest: каждая строка — в словарь, сирот не оставлять).

## 6. Тесты (`tests/Feature/AiAssistantTest.php`)

1. admin и director открывают `/ai` — 200; manager/financist/foreman — 403.
2. Гость — redirect на login.
3. `send` создаёт диалог + 2 сообщения (mock сервиса через `$this->mock(AiAssistantService::class)` —
   реальный API в тестах не дёргать).
4. Пользователь не видит чужой диалог — 403.
5. Валидация: пустое сообщение / >4000 символов — 422.
6. Throttle: 16-й запрос за минуту — 429.
7. Без ключа сервис возвращает сообщение о настройке (не исключение).
8. Дневной лимит: после N запросов — отказ.

## 7. Конфигурация

```env
ANTHROPIC_API_KEY=            # уже есть (SEO)
AI_ASSISTANT_MODEL=claude-opus-5
AI_ASSISTANT_DAILY_LIMIT=100
```

`config/services.php` → `anthropic.assistant_model`, `anthropic.daily_limit`.
В `DEPLOY.md` добавить раздел про эти переменные.

## 8. Этапы реализации

| Фаза | Объём | Критерий готовности |
|---|---|---|
| 1. MVP | миграции, сервис (статический контекст), контроллер, страница чата, gate, тесты | админ задаёт вопрос — получает ответ со знанием цифр системы; менеджер получает 403 |
| 2. Стриминг | SSE-эндпоинт, постепенный вывод ответа | ответ печатается по мере генерации |
| 3. Tool use | toolRunner + 3–5 whitelisted-инструментов чтения БД | помощник сам запрашивает точные данные вместо общего контекста |
| 4. Опционально | вложения (фото/PDF в вопрос), быстрые действия («создай задачу») | по отдельному решению владельца |

## 9. Стоимость (ориентир)

Opus — премиум-модель: один вопрос с контекстом ≈ 3–6 тыс. входных + до 1 тыс.
выходных токенов. При 30–50 вопросах в день — единицы долларов в день.
Если станет дорого: `AI_ASSISTANT_MODEL=claude-sonnet-5` меняется одной переменной,
код не трогается. Кэш контекста (5 мин) уже сокращает расходы.
