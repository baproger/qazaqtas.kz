<?php

namespace App\Services\Ai;

use App\Http\Controllers\ReportController;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\FinanceService;
use App\Services\PayrollService;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Инструменты ИИ-помощника — тонкие обёртки над рабочим кодом системы.
 *
 * Три правила, по которым они спроектированы:
 *  1. Считает СЕРВЕР. Модель получает готовые count и sum — считать элементы
 *     списка она надёжно не умеет, а ошибётся молча.
 *  2. Вопрос «сколько у Ермана» — отдельный инструмент с поиском по части
 *     имени; не нашли — возвращаем {error + список имён}, чтобы модель
 *     переспросила осмысленно, а не выдумала ответ.
 *  3. У каждой сущности есть link — по нему модель делает ссылку в ответе,
 *     и руководитель попадает прямо в карточку.
 *
 * Каждый инструмент выполняется ПРАВАМИ текущего пользователя: нет права —
 * возвращается {error}, и модель вежливо это объясняет.
 */
class AssistantTools
{
    public function __construct(private User $user) {}

    /**
     * Описания инструментов для модели (нейтральный формат;
     * провайдеры перекладывают его в свой синтаксис).
     *
     * @return array<int, array{name: string, description: string, parameters: array<string, mixed>}>
     */
    public function schema(): array
    {
        $period = [
            'from' => ['type' => 'string', 'description' => 'Начало периода ГГГГ-ММ-ДД. Не указано — с начала текущего месяца.'],
            'to' => ['type' => 'string', 'description' => 'Конец периода ГГГГ-ММ-ДД. Не указано — сегодня.'],
        ];

        return [
            [
                'name' => 'sales_report',
                'description' => 'Финансовый итог по сделкам: сумма договоров, оплачено, расходы, налог, чистый остаток, бонусы, прибыль фирмы и маржа. Те же цифры, что в отчёте «Сводный отчёт». БЕЗ периода считает за всё время — как страница отчёта по умолчанию; не подставляй период, если пользователь его не назвал. С периодом фильтрует по дате договора и дополнительно возвращает итог за всё время в поле all_time.',
                'parameters' => ['type' => 'object', 'properties' => [
                    'from' => ['type' => 'string', 'description' => 'Начало периода ГГГГ-ММ-ДД. Только если пользователь назвал период.'],
                    'to' => ['type' => 'string', 'description' => 'Конец периода ГГГГ-ММ-ДД. Только если пользователь назвал период.'],
                ]],
            ],
            [
                'name' => 'deals_list',
                'description' => 'Список сделок с фильтрами и готовыми count/sum. Используй для вопросов «сколько сделок», «какие сделки», «воронка».',
                'parameters' => ['type' => 'object', 'properties' => $period + [
                    'stage' => ['type' => 'string', 'description' => 'Название этапа или его часть.'],
                    'city' => ['type' => 'string', 'description' => 'Город/филиал: Шымкент, Алматы, Тараз.'],
                    'responsible' => ['type' => 'string', 'description' => 'Имя ответственного или его часть.'],
                    'limit' => ['type' => 'integer', 'description' => 'Сколько позиций вернуть, по умолчанию 20.'],
                ]],
            ],
            [
                'name' => 'overdue_deals',
                'description' => 'Просроченные сделки: дедлайн прошёл, сделка не закрыта. Готовые count и sum плюс список с числом дней просрочки.',
                'parameters' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'stock_levels',
                'description' => 'Остатки товаров на складе с отметкой позиций ниже минимума. Для вопросов про склад, запасы, «что заканчивается».',
                'parameters' => ['type' => 'object', 'properties' => [
                    'below_min_only' => ['type' => 'boolean', 'description' => 'Только позиции ниже минимального остатка.'],
                ]],
            ],
            [
                'name' => 'workshop_orders',
                'description' => 'Заказы в производстве (цех): сколько на каком этапе и какие висят дольше трёх дней.',
                'parameters' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'tasks_overview',
                'description' => 'Задачи: сколько открыто и просрочено всего и по каждому ответственному.',
                'parameters' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'employee_summary',
                'description' => 'Сводка по конкретному сотруднику по части имени: его сделки, просрочка, задачи. Используй, когда в вопросе назван человек. Период НЕОБЯЗАТЕЛЕН и фильтрует по дате СОЗДАНИЯ сделки — не подставляй его, если пользователь про период не спрашивал. Инструмент всегда возвращает и общее число сделок, и число за период.',
                'parameters' => ['type' => 'object', 'properties' => $period + [
                    'name' => ['type' => 'string', 'description' => 'Имя или часть имени сотрудника.'],
                ], 'required' => ['name']],
            ],
            [
                'name' => 'cash_balances',
                'description' => 'Касса и банк: остатки денег сейчас (наличные — общие на холдинг, банк — своей фирмы) и движение за период: поступления и расходы по способу оплаты. Для вопросов «сколько денег в кассе», «наличные», «на счёте», «приход/расход».',
                'parameters' => ['type' => 'object', 'properties' => $period],
            ],
            [
                'name' => 'expenses',
                'description' => 'Расходы: сумма и число за всё время и за текущий месяц (или за заданный период), разбивка по категориям, последние записи. Для вопросов «сколько потратили», «на что ушли деньги», «расходы за месяц».',
                'parameters' => ['type' => 'object', 'properties' => $period + [
                    'status' => ['type' => 'string', 'description' => 'confirmed — подтверждённые (по умолчанию), pending — ожидают подтверждения, all — все.'],
                ]],
            ],
            [
                'name' => 'invoices',
                'description' => 'Счета: сколько выставлено, оплачено и не оплачено (сумма к получению), список неоплаченных с клиентом и датой. Для вопросов «какие счета не оплачены», «сколько нам должны по счетам».',
                'parameters' => ['type' => 'object', 'properties' => $period],
            ],
            [
                'name' => 'debts',
                'description' => 'Задолженности: дебиторская (нам должны) и кредиторская (мы должны) по контрагентам, плюс долги сотрудников перед компанией. Для вопросов «кто нам должен», «кому мы должны», «долги».',
                'parameters' => ['type' => 'object', 'properties' => (object) []],
            ],
            [
                'name' => 'payroll',
                'description' => 'Зарплата за месяц по сотрудникам: оклад, часы, начисленный бонус, выплаченный бонус. Для вопросов «сколько зарплата», «какой бонус у …», «фонд оплаты труда».',
                'parameters' => ['type' => 'object', 'properties' => [
                    'month' => ['type' => 'string', 'description' => 'Месяц ГГГГ-ММ. Не указан — текущий.'],
                    'name' => ['type' => 'string', 'description' => 'Имя сотрудника или его часть — если спросили про одного.'],
                ]],
            ],
            [
                'name' => 'site_orders',
                'description' => 'Заказы с сайта (витрина): сколько новых, в работе, всего на какую сумму; последние заказы с городом, суммой и статусом. Для вопросов «заказы с сайта», «сколько заявок».',
                'parameters' => ['type' => 'object', 'properties' => $period],
            ],
            [
                'name' => 'clients_list',
                'description' => 'Справочник контрагентов: сколько клиентов, у кого сколько сделок и на какую сумму, ответственный менеджер. Для вопросов «сколько у нас клиентов», «список клиентов», «крупнейшие клиенты».',
                'parameters' => ['type' => 'object', 'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'Сколько клиентов вернуть, по умолчанию 20.'],
                ]],
            ],
            [
                'name' => 'client_summary',
                'description' => 'Сводка по клиенту по части названия: его сделки (count и sum), оплаты, последние сделки. Используй, когда в вопросе назван клиент или заказчик.',
                'parameters' => ['type' => 'object', 'properties' => ['name' => [
                    'type' => 'string', 'description' => 'Название клиента или его часть.',
                ]], 'required' => ['name']],
            ],
        ];
    }

    /**
     * Выполнить инструмент. Всегда возвращает массив — модель получает
     * либо данные, либо {error} с объяснением.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function run(string $name, array $args): array
    {
        try {
            return match ($name) {
                'sales_report' => $this->salesReport($args),
                'deals_list' => $this->dealsList($args),
                'overdue_deals' => $this->overdueDeals(),
                'stock_levels' => $this->stockLevels($args),
                'workshop_orders' => $this->workshopOrders(),
                'tasks_overview' => $this->tasksOverview(),
                'employee_summary' => $this->employeeSummary($args),
                'client_summary' => $this->clientSummary($args),
                'cash_balances' => $this->cashBalances($args),
                'expenses' => $this->expenses($args),
                'invoices' => $this->invoices($args),
                'debts' => $this->debts(),
                'payroll' => $this->payroll($args),
                'site_orders' => $this->siteOrders($args),
                'clients_list' => $this->clientsList($args),
                default => ['error' => "Инструмент «{$name}» не существует."],
            };
        } catch (\Throwable $e) {
            report($e);

            return ['error' => 'Не удалось получить данные из системы: '.$e->getMessage()];
        }
    }

    // ------------------------------------------------------------------
    // Инструменты
    // ------------------------------------------------------------------

    /** @param array<string, mixed> $args */
    private function salesReport(array $args): array
    {
        if (! $this->user->can('report.viewAny')) {
            return ['error' => 'У вас нет доступа к финансовому отчёту — цифры по прибыли я показать не могу.'];
        }

        // Без дат — всё время, ровно как страница отчёта без фильтра.
        // Раньше умолчанием был текущий месяц, и на вопрос «общая сумма
        // договоров» помощник называл 1 660 000 вместо 13 160 000 со страницы.
        [$from, $to] = $this->period($args, wholeTime: true);

        $result = $this->reportTotals(null, null) + [
            'scope' => 'за всё время',
            'currency' => 'KZT',
            'hint' => 'company_profit — чистая прибыль фирмы после налога и бонусов; remainder — остаток до бонусов.',
        ];

        if ($from) {
            // Период спрошен — отдаём его ПЕРВЫМ, а итог за всё время рядом:
            // модель обязана назвать обе величины и не путать их.
            $result = $this->reportTotals($from, $to) + [
                'scope' => 'период '.$this->periodLabel($from, $to).' (по дате договора)',
                'all_time' => $this->reportTotals(null, null),
                'currency' => 'KZT',
                'hint' => 'Верхний уровень — только за период; all_time — за всё время. Назови обе, если вопрос про «общую» сумму.',
            ];
        }

        return $result;
    }

    /**
     * Итоги «Сводного отчёта» — тем же кодом, что рисует страницу: помощник
     * обязан называть цифры, совпадающие с отчётом до тенге.
     *
     * @return array<string, mixed>
     */
    private function reportTotals(?Carbon $from, ?Carbon $to): array
    {
        $request = Request::create('/reports/deals', 'GET', array_filter([
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
        ]));
        $request->setUserResolver(fn () => $this->user);

        $totals = app(ReportController::class)->assistantTotals($request);

        return [
            'deals_count' => $totals['count'] ?? 0,
            'contracts_sum' => $totals['budget'] ?? 0,
            'paid' => $totals['paid'] ?? 0,
            'expenses_material' => $totals['material'] ?? 0,
            'expenses_delivery' => $totals['delivery'] ?? 0,
            'tax' => $totals['tax'] ?? 0,
            'remainder' => $totals['remainder'] ?? 0,
            'manager_bonus' => $totals['bonus'] ?? 0,
            'company_profit' => $totals['company'] ?? 0,
            'margin_percent' => $totals['margin'] ?? 0,
        ];
    }

    /** @param array<string, mixed> $args */
    private function dealsList(array $args): array
    {
        if (! $this->user->can('deal.viewAny')) {
            return ['error' => 'У вас нет доступа к сделкам.'];
        }

        $limit = max(1, min(50, (int) ($args['limit'] ?? 20)));
        $query = $this->dealsQuery($args);

        $totals = (clone $query)->selectRaw('COUNT(*) as cnt, COALESCE(SUM(deals.budget), 0) as total')->first();

        $items = (clone $query)
            ->leftJoin('users', 'users.id', '=', 'deals.responsible_user_id')
            ->orderByDesc('deals.created_at')
            ->limit($limit)
            ->get([
                'deals.id', 'deals.number', 'deals.client_name', 'deals.company_name', 'deals.budget',
                'deals.deadline', 'deal_stages.name as stage', 'users.name as responsible',
            ]);

        return [
            'count' => (int) $totals->cnt,
            'sum' => (float) $totals->total,
            'average' => $totals->cnt > 0 ? round($totals->total / $totals->cnt, 2) : 0,
            'shown' => $items->count(),
            'items' => $items->map(fn ($d) => [
                'number' => $d->number,
                'client' => $d->client_name ?: $d->company_name ?: '—',
                'budget' => (float) $d->budget,
                'stage' => $d->stage,
                'deadline' => $d->deadline,
                'responsible' => $d->responsible,
                'link' => route('deals.show', $d->id, false),
            ])->all(),
        ];
    }

    private function overdueDeals(): array
    {
        if (! $this->user->can('deal.viewAny')) {
            return ['error' => 'У вас нет доступа к сделкам.'];
        }

        $rows = $this->baseDeals()
            ->leftJoin('users', 'users.id', '=', 'deals.responsible_user_id')
            ->where('deal_stages.is_won', false)
            ->whereNotNull('deals.deadline')
            ->whereDate('deals.deadline', '<', now()->toDateString())
            ->orderBy('deals.deadline')
            ->limit(50)
            ->get([
                'deals.id', 'deals.number', 'deals.client_name', 'deals.company_name',
                'deals.budget', 'deals.deadline', 'deal_stages.name as stage', 'users.name as responsible',
            ]);

        return [
            'count' => $rows->count(),
            'sum' => (float) $rows->sum('budget'),
            'items' => $rows->map(fn ($d) => [
                'number' => $d->number,
                'client' => $d->client_name ?: $d->company_name ?: '—',
                'budget' => (float) $d->budget,
                'stage' => $d->stage,
                'deadline' => $d->deadline,
                // abs(): разница дат в Carbon 3 знаковая, модель получала минус.
                'days_overdue' => (int) abs(now()->startOfDay()->diffInDays(Carbon::parse($d->deadline)->startOfDay())),
                'responsible' => $d->responsible ?: 'без ответственного',
                'link' => route('deals.show', $d->id, false),
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $args */
    private function stockLevels(array $args): array
    {
        if (! $this->user->can('expense.viewAny')) {
            return ['error' => 'У вас нет доступа к складу.'];
        }

        $rows = DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->whereNull('products.deleted_at')
            ->groupBy('products.id', 'products.name', 'products.unit', 'products.min_stock')
            ->havingRaw('SUM(stock_movements.qty) <> 0')
            ->orderByRaw('SUM(stock_movements.qty) DESC')
            ->get([
                'products.name', 'products.unit', 'products.min_stock',
                DB::raw('SUM(stock_movements.qty) as qty'),
            ])
            ->map(fn ($r) => [
                'product' => $r->name,
                'qty' => round((float) $r->qty, 2),
                'unit' => $r->unit,
                'min_stock' => $r->min_stock !== null ? (float) $r->min_stock : null,
                'below_min' => $r->min_stock !== null && (float) $r->qty < (float) $r->min_stock,
            ]);

        $low = $rows->where('below_min', true)->values();

        return [
            'count' => $rows->count(),
            'below_min_count' => $low->count(),
            'items' => (($args['below_min_only'] ?? false) ? $low : $rows)->all(),
        ];
    }

    private function workshopOrders(): array
    {
        if (! $this->user->can('project.viewAny')) {
            return ['error' => 'У вас нет доступа к производству.'];
        }

        $byStage = DB::table('projects')
            ->join('project_stages', 'project_stages.id', '=', 'projects.project_stage_id')
            ->whereNull('projects.deleted_at')
            ->groupBy('project_stages.id', 'project_stages.name', 'project_stages.order')
            ->orderBy('project_stages.order')
            ->get(['project_stages.name as stage', DB::raw('COUNT(*) as cnt')]);

        $stuck = DB::table('projects')
            ->join('project_stages', 'project_stages.id', '=', 'projects.project_stage_id')
            ->leftJoin('deals', 'deals.id', '=', 'projects.deal_id')
            ->whereNull('projects.deleted_at')
            ->where('project_stages.is_completed', false)
            ->where('projects.created_at', '<', now()->subDays(3))
            ->orderBy('projects.created_at')
            ->limit(20)
            ->get(['projects.id', 'projects.number', 'projects.created_at', 'deals.client_name', 'project_stages.name as stage']);

        return [
            'total' => (int) $byStage->sum('cnt'),
            'by_stage' => $byStage->map(fn ($r) => ['stage' => $r->stage, 'count' => (int) $r->cnt])->all(),
            'stuck_count' => $stuck->count(),
            'stuck' => $stuck->map(fn ($p) => [
                'number' => $p->number,
                'client' => $p->client_name ?: '—',
                'stage' => $p->stage,
                'days_in_work' => (int) abs(now()->diffInDays(Carbon::parse($p->created_at))),
                'link' => route('projects.show', $p->id, false),
            ])->all(),
        ];
    }

    private function tasksOverview(): array
    {
        if (! $this->user->can('task.viewAny')) {
            return ['error' => 'У вас нет доступа к задачам.'];
        }

        $open = DB::table('tasks')->whereNull('deleted_at')->where('status', '!=', 'done')->count();

        $overdue = DB::table('tasks')
            ->leftJoin('users', 'users.id', '=', 'tasks.assignee_id')
            ->whereNull('tasks.deleted_at')->where('tasks.status', '!=', 'done')
            ->whereNotNull('tasks.due_date')
            ->whereDate('tasks.due_date', '<', now()->toDateString())
            ->orderBy('tasks.due_date')
            ->limit(30)
            ->get(['tasks.title', 'tasks.due_date', 'users.name as assignee']);

        $byUser = DB::table('tasks')
            ->join('users', 'users.id', '=', 'tasks.assignee_id')
            ->whereNull('tasks.deleted_at')->where('tasks.status', '!=', 'done')
            ->groupBy('users.id', 'users.name')
            ->orderByRaw('COUNT(*) DESC')
            ->get(['users.name', DB::raw('COUNT(*) as cnt')]);

        return [
            'open_count' => $open,
            'overdue_count' => $overdue->count(),
            'by_assignee' => $byUser->map(fn ($u) => ['name' => $u->name, 'open' => (int) $u->cnt])->all(),
            'overdue' => $overdue->map(fn ($t) => [
                'title' => $t->title,
                'due_date' => $t->due_date,
                'assignee' => $t->assignee ?: 'без ответственного',
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $args */
    private function employeeSummary(array $args): array
    {
        $needle = trim((string) ($args['name'] ?? ''));

        if ($needle === '') {
            return ['error' => 'Не указано имя сотрудника.'];
        }

        $people = User::query()->where('is_active', true)->get(['id', 'name']);
        $found = $people->first(fn ($u) => mb_stripos($u->name, $needle) !== false);

        if (! $found) {
            // Не угадываем: отдаём список, чтобы модель переспросила по делу.
            return [
                'error' => "Сотрудник «{$needle}» не найден.",
                'available' => $people->pluck('name')->all(),
            ];
        }

        [$from, $to] = $this->period($args, wholeTime: true);

        // Считаем ВСЕГДА обе величины: всего у человека и сколько из них
        // попало в спрошенный период. Иначе модель, сама подставив текущий
        // месяц, отвечала «1 сделка» там, где всего их две, — и человек
        // видел цифру, не сходящуюся с его собственным списком.
        $all = $this->baseDeals()
            ->where('deals.responsible_user_id', $found->id)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(deals.budget), 0) as total')
            ->first();

        $deals = $from
            ? $this->baseDeals()
                ->where('deals.responsible_user_id', $found->id)
                ->whereBetween('deals.created_at', [$from, $to])
                ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(deals.budget), 0) as total')
                ->first()
            : $all;

        $overdue = $this->baseDeals()
            ->where('deals.responsible_user_id', $found->id)
            ->where('deal_stages.is_won', false)
            ->whereNotNull('deals.deadline')
            ->whereDate('deals.deadline', '<', now()->toDateString())
            ->count();

        $tasks = DB::table('tasks')->whereNull('deleted_at')
            ->where('assignee_id', $found->id)->where('status', '!=', 'done');

        $recent = $this->baseDeals()
            ->where('deals.responsible_user_id', $found->id)
            ->when($from, fn ($q) => $q->whereBetween('deals.created_at', [$from, $to]))
            ->orderByDesc('deals.created_at')->limit(10)
            ->get(['deals.id', 'deals.number', 'deals.client_name', 'deals.company_name', 'deals.budget', 'deal_stages.name as stage']);

        return [
            'employee' => $found->name,
            'link' => route('users.show', $found->id, false),
            'period' => $from ? $this->periodLabel($from, $to) : 'за всё время',
            'deals_count_total' => (int) $all->cnt,
            'deals_sum_total' => (float) $all->total,
            'deals_count_in_period' => (int) $deals->cnt,
            'deals_sum_in_period' => (float) $deals->total,
            'hint' => $from
                ? 'deals_count_in_period — только сделки, СОЗДАННЫЕ в этом периоде; deals_count_total — все сделки сотрудника. Назови обе величины, чтобы не путать.'
                : 'Период не задан: обе величины совпадают и означают все сделки сотрудника.',
            'overdue_deals_count' => $overdue,
            'open_tasks' => (clone $tasks)->count(),
            'overdue_tasks' => (clone $tasks)->whereNotNull('due_date')
                ->whereDate('due_date', '<', now()->toDateString())->count(),
            'recent_deals' => $recent->map(fn ($d) => [
                'number' => $d->number,
                'client' => $d->client_name ?: $d->company_name ?: '—',
                'budget' => (float) $d->budget,
                'stage' => $d->stage,
                'link' => route('deals.show', $d->id, false),
            ])->all(),
        ];
    }

    /** @param array<string, mixed> $args */
    private function clientSummary(array $args): array
    {
        if (! $this->user->can('deal.viewAny')) {
            return ['error' => 'У вас нет доступа к сделкам клиентов.'];
        }

        $needle = trim((string) ($args['name'] ?? ''));

        if ($needle === '') {
            return ['error' => 'Не указано название клиента.'];
        }

        $names = $this->baseDeals()
            ->selectRaw("COALESCE(NULLIF(deals.client_name, ''), NULLIF(deals.company_name, '')) as client")
            ->distinct()->pluck('client')->filter()->values();

        $match = $names->first(fn ($n) => mb_stripos($n, $needle) !== false);

        if (! $match) {
            return [
                'error' => "Клиент «{$needle}» не найден среди сделок.",
                'available' => $names->take(50)->all(),
            ];
        }

        $rows = $this->baseDeals()
            ->where(fn ($q) => $q->where('deals.client_name', $match)->orWhere('deals.company_name', $match))
            ->orderByDesc('deals.created_at')
            ->get(['deals.id', 'deals.number', 'deals.budget', 'deals.deadline', 'deal_stages.name as stage']);

        return [
            'client' => $match,
            'deals_count' => $rows->count(),
            'deals_sum' => (float) $rows->sum('budget'),
            'items' => $rows->take(20)->map(fn ($d) => [
                'number' => $d->number,
                'budget' => (float) $d->budget,
                'stage' => $d->stage,
                'deadline' => $d->deadline,
                'link' => route('deals.show', $d->id, false),
            ])->values()->all(),
        ];
    }

    // ------------------------------------------------------------------
    // Финансы, зарплата, витрина, справочник
    // ------------------------------------------------------------------

    /** Касса: те же скоупы и остатки, что плитки «Финансов» (FinanceService). */
    private function cashBalances(array $args): array
    {
        if (! ($this->user->hasAnyRole(['admin', 'director', 'financist']) && $this->user->can('payment.viewAny'))) {
            return ['error' => 'Касса открыта только бухгалтерии и руководству.'];
        }

        $finance = app(FinanceService::class);
        $companyId = CurrentCompany::id();
        $balances = $finance->companyBalances($companyId);

        $flow = function (?Carbon $from, ?Carbon $to) use ($finance, $companyId) {
            $between = fn ($q, string $col) => $q
                ->when($from, fn ($x) => $x->where($col, '>=', $from->copy()->startOfDay()))
                ->when($to, fn ($x) => $x->where($col, '<=', $to->copy()->endOfDay()));

            $payments = $between(
                DB::table('payments')->join('invoices', 'invoices.id', '=', 'payments.invoice_id')->whereNull('invoices.deleted_at'),
                'payments.payment_date',
            )->selectRaw("SUM(CASE WHEN payments.payment_method = 'cash' THEN payments.amount ELSE 0 END) as cash, SUM(CASE WHEN payments.payment_method = 'cash' THEN 0 ELSE payments.amount END) as bank")->first();

            $receipts = $between(DB::table('cash_receipts')->when($companyId, fn ($q) => $q->where('company_id', $companyId)), 'date')
                ->selectRaw("SUM(CASE WHEN method = 'cash' THEN amount ELSE 0 END) as cash, SUM(CASE WHEN method = 'cash' THEN 0 ELSE amount END) as bank")->first();

            $expenses = $between($finance->scopeCompanyExpenses(Expense::query()->where('status', 'confirmed'), $companyId), 'date')
                ->selectRaw("SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END) as cash, SUM(CASE WHEN payment_method = 'cash' THEN 0 ELSE amount END) as bank")->first();

            return [
                'income_cash' => round((float) $payments->cash + (float) $receipts->cash, 2),
                'income_bank' => round((float) $payments->bank + (float) $receipts->bank, 2),
                'outcome_cash' => round((float) $expenses->cash, 2),
                'outcome_bank' => round((float) $expenses->bank, 2),
            ];
        };

        return [
            'balance_now' => [
                'cash' => round((float) $balances['cash'], 2),
                'bank' => round((float) $balances['bank'], 2),
                'total' => round((float) $balances['cash'] + (float) $balances['bank'], 2),
            ],
        ] + $this->periods($args, $flow) + [
            'currency' => 'KZT',
            'hint' => 'balance_now — деньги сейчас (cash — наличные, единая касса холдинга; bank — счета своей фирмы). income/outcome — движение за указанный период.',
        ];
    }

    /** Расходы: подтверждённые по умолчанию, разбивка по категориям, последние записи. */
    private function expenses(array $args): array
    {
        if (! $this->user->can('expense.viewAny')) {
            return ['error' => 'У вас нет доступа к расходам.'];
        }

        $status = (string) ($args['status'] ?? 'confirmed');
        $base = fn () => app(FinanceService::class)
            ->scopeCompanyExpenses(Expense::query()->when($status !== 'all', fn ($q) => $q->where('status', $status)), CurrentCompany::id());

        $calc = function (?Carbon $from, ?Carbon $to) use ($base) {
            $q = $base()
                ->when($from, fn ($x) => $x->where('date', '>=', $from->copy()->startOfDay()))
                ->when($to, fn ($x) => $x->where('date', '<=', $to->copy()->endOfDay()));

            $byCat = (clone $q)->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
                ->groupBy('expense_categories.name')
                ->orderByRaw('SUM(expenses.amount) DESC')
                ->selectRaw("COALESCE(expense_categories.name, 'Без категории') as category, COUNT(*) as cnt, SUM(expenses.amount) as total")
                ->get();

            return [
                'count' => (int) $byCat->sum('cnt'),
                'sum' => round((float) $byCat->sum('total'), 2),
                'by_category' => $byCat->map(fn ($r) => ['category' => $r->category, 'count' => (int) $r->cnt, 'sum' => round((float) $r->total, 2)])->all(),
            ];
        };

        $recent = $base()->with('category:id,name')->orderByDesc('date')->limit(15)->get()
            ->map(fn ($e) => [
                'date' => optional($e->date)->format('Y-m-d') ?? (string) $e->date,
                'amount' => (float) $e->amount,
                'category' => $e->category?->name ?? 'Без категории',
                'description' => (string) $e->description,
                'status' => $e->status,
                'method' => $e->payment_method,
            ])->all();

        return $this->periods($args, $calc) + [
            'status_filter' => $status,
            'recent' => $recent,
            'link' => $this->link('expensesBoard.index'),
            'currency' => 'KZT',
        ];
    }

    /** Счета: выставлено / оплачено / остаток к получению, неоплаченные списком. */
    private function invoices(array $args): array
    {
        if (! $this->user->can('invoice.viewAny')) {
            return ['error' => 'У вас нет доступа к счетам.'];
        }

        $base = fn () => app(FinanceService::class)->scopeCompanyInvoices(Invoice::query(), CurrentCompany::id());

        $calc = function (?Carbon $from, ?Carbon $to) use ($base) {
            $q = $base()
                ->when($from, fn ($x) => $x->where('issue_date', '>=', $from->copy()->startOfDay()))
                ->when($to, fn ($x) => $x->where('issue_date', '<=', $to->copy()->endOfDay()));
            $ids = (clone $q)->pluck('id');
            $issued = (float) (clone $q)->sum('amount');
            $paid = (float) DB::table('payments')->whereIn('invoice_id', $ids)->sum('amount');

            return [
                'count' => $ids->count(),
                'issued_sum' => round($issued, 2),
                'paid_sum' => round($paid, 2),
                'unpaid_sum' => round(max(0, $issued - $paid), 2),
                'by_status' => (clone $q)->groupBy('status')->selectRaw('status, COUNT(*) as cnt, SUM(amount) as total')->get()
                    ->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->cnt, 'sum' => round((float) $r->total, 2)])->all(),
            ];
        };

        $unpaid = $base()->where('status', '!=', 'paid')->with('client:id,name')->orderBy('due_date')->limit(20)->get()
            ->map(function ($i) {
                $paid = (float) DB::table('payments')->where('invoice_id', $i->id)->sum('amount');

                return [
                    'number' => $i->number,
                    'client' => $i->client?->name ?? '—',
                    'amount' => (float) $i->amount,
                    'paid' => $paid,
                    'remaining' => round(max(0, (float) $i->amount - $paid), 2),
                    'status' => $i->status,
                    'due_date' => optional($i->due_date)->format('Y-m-d') ?? (string) $i->due_date,
                    'overdue' => $i->due_date && Carbon::parse($i->due_date)->lt(now()->startOfDay()),
                ];
            })->all();

        return $this->periods($args, $calc) + [
            'unpaid_invoices' => $unpaid,
            'link' => $this->link('finance.invoices'),
            'currency' => 'KZT',
            'hint' => 'Статусы: sent — выставлен, partially_paid — оплачен частично, paid — оплачен.',
        ];
    }

    /** Долги контрагентов (дебиторка/кредиторка) и долги сотрудников. */
    private function debts(): array
    {
        if (! ($this->user->hasAnyRole(['admin', 'director', 'financist']) && $this->user->can('invoice.viewAny'))) {
            return ['error' => 'Задолженности видит бухгалтерия и руководство.'];
        }

        $companyId = CurrentCompany::id();
        $rows = DB::table('debts')->when($companyId, fn ($q) => $q->where('company_id', $companyId))->orderByDesc('amount')->get();
        $side = fn (string $type) => $rows->where('type', $type)->values();

        $employees = DB::table('employee_debts')->join('users', 'users.id', '=', 'employee_debts.user_id')
            ->whereNull('employee_debts.closed_at')
            ->when($companyId, fn ($q) => $q->where('employee_debts.company_id', $companyId))
            ->orderByDesc('employee_debts.amount')
            ->get(['users.name', 'employee_debts.amount', 'employee_debts.monthly_payment', 'employee_debts.note']);

        $fmt = fn ($c) => $c->map(fn ($d) => ['counterparty' => $d->counterparty, 'amount' => (float) $d->amount, 'date' => $d->date, 'note' => $d->note])->all();

        return [
            'receivable' => ['count' => $side('receivable')->count(), 'sum' => round((float) $side('receivable')->sum('amount'), 2), 'items' => $fmt($side('receivable'))],
            'payable' => ['count' => $side('payable')->count(), 'sum' => round((float) $side('payable')->sum('amount'), 2), 'items' => $fmt($side('payable'))],
            'employee_debts' => [
                'count' => $employees->count(),
                'sum' => round((float) $employees->sum('amount'), 2),
                'items' => $employees->map(fn ($e) => ['employee' => $e->name, 'amount' => (float) $e->amount, 'monthly_payment' => (float) $e->monthly_payment, 'note' => $e->note])->all(),
            ],
            'link' => $this->link('finance.debts'),
            'currency' => 'KZT',
            'hint' => 'receivable — нам должны (дебиторская), payable — мы должны (кредиторская), employee_debts — сотрудники должны компании.',
        ];
    }

    /** Зарплата за месяц: оклад, часы, бонус начисленный и выплаченный. */
    private function payroll(array $args): array
    {
        if (! $this->user->can('payroll.view')) {
            return ['error' => 'У вас нет доступа к зарплате.'];
        }

        $month = preg_match('/^\d{4}-\d{2}$/', (string) ($args['month'] ?? '')) ? $args['month'] : now()->format('Y-m');
        $needle = trim((string) ($args['name'] ?? ''));

        $people = User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'salary']);
        if ($needle !== '') {
            $people = $people->filter(fn ($u) => mb_stripos($u->name, $needle) !== false)->values();
            if ($people->isEmpty()) {
                return ['error' => "Сотрудник «{$needle}» не найден.", 'available' => User::where('is_active', true)->pluck('name')->all()];
            }
        }

        $bonuses = app(PayrollService::class)->bonusByUsersForMonth($people->pluck('id'), $month);
        $hours = DB::table('work_hours')->where('month', $month)->pluck('hours', 'user_id');
        $paid = DB::table('bonus_payouts')->where('month', $month)->groupBy('user_id')->selectRaw('user_id, SUM(amount) as s')->pluck('s', 'user_id');

        $rows = $people->map(fn ($u) => [
            'employee' => $u->name,
            'link' => $this->link('users.show', $u->id),
            'salary' => (float) ($u->salary ?? 0),
            'hours' => (float) ($hours[$u->id] ?? 0),
            'bonus_accrued' => round((float) ($bonuses[$u->id] ?? 0), 2),
            'bonus_paid' => round((float) ($paid[$u->id] ?? 0), 2),
        ]);

        return [
            'month' => $month,
            'count' => $rows->count(),
            'salary_total' => round((float) $rows->sum('salary'), 2),
            'bonus_accrued_total' => round((float) $rows->sum('bonus_accrued'), 2),
            'bonus_paid_total' => round((float) $rows->sum('bonus_paid'), 2),
            'employees' => $rows->all(),
            'link' => $this->link('payroll.index'),
            'currency' => 'KZT',
            'hint' => 'salary — оклад по карточке сотрудника; bonus_accrued — бонус, начисленный по сделкам месяца; bonus_paid — уже выплачено.',
        ];
    }

    /** Заказы с сайта: по статусам и последние. */
    private function siteOrders(array $args): array
    {
        if (! ($this->user->hasAnyRole(['admin', 'director', 'financist', 'manager']) && $this->user->can('deal.viewAny'))) {
            return ['error' => 'У вас нет доступа к заказам с сайта.'];
        }

        $base = fn () => Order::query()->when(CurrentCompany::id(), fn ($q, $c) => $q->where('company_id', $c));

        $calc = function (?Carbon $from, ?Carbon $to) use ($base) {
            $q = $base()
                ->when($from, fn ($x) => $x->where('created_at', '>=', $from))
                ->when($to, fn ($x) => $x->where('created_at', '<=', $to));

            return [
                'count' => (clone $q)->count(),
                'sum' => round((float) (clone $q)->sum('total'), 2),
                'by_status' => (clone $q)->groupBy('status')->selectRaw('status, COUNT(*) as cnt, SUM(total) as total')->get()
                    ->map(fn ($r) => ['status' => $r->status, 'count' => (int) $r->cnt, 'sum' => round((float) $r->total, 2)])->all(),
            ];
        };

        $recent = $base()->with(['deal:id,number', 'manager:id,name'])->latest()->limit(15)->get()
            ->map(fn ($o) => [
                'number' => $o->number,
                'customer' => $o->name,
                'city' => $o->city,
                'total' => (float) $o->total,
                'status' => $o->status,
                'manager' => $o->manager?->name,
                'deal' => $o->deal?->number,
                'created_at' => optional($o->created_at)->toDateString(),
                'link' => $this->link('siteOrders.index'),
            ])->all();

        return $this->periods($args, $calc) + ['recent' => $recent, 'currency' => 'KZT'];
    }

    /** Справочник контрагентов с числом и суммой сделок. */
    private function clientsList(array $args): array
    {
        if (! $this->user->can('viewAny', Client::class)) {
            return ['error' => 'У вас нет доступа к справочнику контрагентов.'];
        }

        $limit = max(1, min(50, (int) ($args['limit'] ?? 20)));
        $total = Client::query()->count();

        $rows = Client::query()->with('responsible:id,name')
            ->leftJoin('deals', fn ($j) => $j->on('deals.client_id', '=', 'clients.id')->whereNull('deals.deleted_at'))
            ->groupBy('clients.id')
            ->orderByRaw('COALESCE(SUM(deals.budget), 0) DESC')
            ->limit($limit)
            ->selectRaw('clients.*, COUNT(deals.id) as deals_cnt, COALESCE(SUM(deals.budget), 0) as deals_sum')
            ->get()
            ->map(fn ($c) => [
                'name' => $c->name,
                'type' => $c->type,
                'phone' => $c->phone,
                'responsible' => $c->responsible?->name,
                'deals_count' => (int) $c->deals_cnt,
                'deals_sum' => round((float) $c->deals_sum, 2),
                'link' => $this->link('clients.show', $c->id),
            ]);

        return [
            'count' => $total,
            'shown' => $rows->count(),
            'items' => $rows->all(),
            'currency' => 'KZT',
            // Клиентов часто заводят прямо в сделке текстом, минуя справочник.
            'hint' => $total === 0
                ? 'Справочник пуст, но клиенты могут быть записаны в сделках текстом — для «крупнейших клиентов» используй deals_list или client_summary.'
                : 'deals_sum — сумма сделок, привязанных к карточке клиента.',
        ];
    }

    // ------------------------------------------------------------------
    // Общее
    // ------------------------------------------------------------------

    /**
     * Единая логика периодов для денежных инструментов: без дат — всё
     * время плюс текущий месяц рядом; с датами — период плюс всё время.
     * Модель всегда получает обе величины и не путает «за месяц» с «всего».
     *
     * @param  callable(?Carbon, ?Carbon): array<string, mixed>  $calc
     * @return array<string, mixed>
     */
    private function periods(array $args, callable $calc): array
    {
        [$from, $to] = $this->period($args, wholeTime: true);

        if ($from) {
            return $calc($from, $to) + [
                'scope' => 'период '.$this->periodLabel($from, $to),
                'all_time' => $calc(null, null),
            ];
        }

        return $calc(null, null) + [
            'scope' => 'за всё время',
            'current_month' => $calc(now()->startOfMonth(), now()) + ['scope' => 'текущий месяц'],
        ];
    }

    /** Ссылка на страницу, если такой маршрут есть — иначе null, а не падение. */
    private function link(string $route, mixed $params = []): ?string
    {
        return Route::has($route) ? route($route, $params, false) : null;
    }

    private function baseDeals(): \Illuminate\Database\Query\Builder
    {
        return DB::table('deals')
            ->join('deal_stages', 'deal_stages.id', '=', 'deals.deal_stage_id')
            ->whereNull('deals.deleted_at');
    }

    /** @param array<string, mixed> $args */
    private function dealsQuery(array $args): \Illuminate\Database\Query\Builder
    {
        [$from, $to] = $this->period($args, wholeTime: true);

        return $this->baseDeals()
            ->when($from, fn ($q) => $q->whereBetween('deals.created_at', [$from, $to]))
            // Имена и этапы сопоставляем в PHP: SQL LIKE не различает регистр
            // только у латиницы, и «карыздар» не нашёл бы «Карыздар».
            ->when($args['stage'] ?? null, fn ($q, $stage) => $q->whereIn(
                'deals.deal_stage_id',
                DB::table('deal_stages')->pluck('name', 'id')
                    ->filter(fn ($n) => mb_stripos($n, $stage) !== false)->keys()->all() ?: [0],
            ))
            ->when($args['city'] ?? null, fn ($q, $city) => $q->where(fn ($w) => $w
                ->where('deals.branch', 'like', '%'.$city.'%')
                ->orWhere('deals.address', 'like', '%'.$city.'%')))
            ->when($args['responsible'] ?? null, fn ($q, $who) => $q->whereIn(
                'deals.responsible_user_id',
                User::query()->pluck('name', 'id')
                    ->filter(fn ($n) => mb_stripos($n, $who) !== false)->keys()->all() ?: [0],
            ));
    }

    /**
     * Период из аргументов. wholeTime: без дат вернуть [null, null]
     * (значит «за всё время»), иначе — текущий месяц.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function period(array $args, bool $wholeTime = false): array
    {
        $from = ! empty($args['from']) ? Carbon::parse($args['from'])->startOfDay() : null;
        $to = ! empty($args['to']) ? Carbon::parse($args['to'])->endOfDay() : null;

        if ($from === null && $to === null && $wholeTime) {
            return [null, null];
        }

        return [$from ?? now()->startOfMonth(), $to ?? now()->endOfDay()];
    }

    private function periodLabel(Carbon $from, Carbon $to): string
    {
        return $from->format('d.m.Y').' — '.$to->format('d.m.Y');
    }
}
