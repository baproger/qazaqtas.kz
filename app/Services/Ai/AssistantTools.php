<?php

namespace App\Services\Ai;

use App\Http\Controllers\ReportController;
use App\Models\User;
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
                'description' => 'Финансовый итог по сделкам за период: сумма договоров, оплачено, расходы, налог, чистый остаток, бонусы, прибыль фирмы и маржа. Те же цифры, что в отчёте «Сводный отчёт». Используй для вопросов о выручке, прибыли, марже.',
                'parameters' => ['type' => 'object', 'properties' => $period],
            ],
            [
                'name' => 'deals_list',
                'description' => 'Список сделок с фильтрами и готовыми count/sum. Используй для вопросов «сколько сделок», «какие сделки», «воронка».',
                'parameters' => ['type' => 'object', 'properties' => $period + [
                    'stage' => ['type' => 'string', 'description' => 'Название этапа или его часть.'],
                    'city' => ['type' => 'string', 'description' => 'Город/филиал: Шымкент, Алматы, Тараз.'],
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
                'description' => 'Сводка по конкретному сотруднику по части имени: его сделки (count и sum), просрочка, задачи. Используй, когда в вопросе назван человек.',
                'parameters' => ['type' => 'object', 'properties' => $period + [
                    'name' => ['type' => 'string', 'description' => 'Имя или часть имени сотрудника.'],
                ], 'required' => ['name']],
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

        [$from, $to] = $this->period($args);

        // Тот же расчёт, что на странице «Сводный отчёт»: помощник обязан
        // называть цифры, совпадающие с отчётом до тенге.
        $request = Request::create('/reports/deals', 'GET', [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ]);
        $request->setUserResolver(fn () => $this->user);

        $totals = app(ReportController::class)->assistantTotals($request);

        return [
            'period' => $this->periodLabel($from, $to),
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
            'currency' => 'KZT',
            'hint' => 'company_profit — чистая прибыль фирмы после налога и бонусов; remainder — остаток до бонусов.',
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
                'days_overdue' => (int) now()->startOfDay()->diffInDays(Carbon::parse($d->deadline)->startOfDay()),
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
                'days_in_work' => (int) now()->diffInDays(Carbon::parse($p->created_at)),
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

        $deals = $this->baseDeals()
            ->where('deals.responsible_user_id', $found->id)
            ->when($from, fn ($q) => $q->whereBetween('deals.created_at', [$from, $to]))
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(deals.budget), 0) as total')
            ->first();

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
            'deals_count' => (int) $deals->cnt,
            'deals_sum' => (float) $deals->total,
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
    // Общее
    // ------------------------------------------------------------------

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
            ->when($args['stage'] ?? null, fn ($q, $stage) => $q->where('deal_stages.name', 'like', '%'.$stage.'%'))
            ->when($args['city'] ?? null, fn ($q, $city) => $q->where(fn ($w) => $w
                ->where('deals.branch', 'like', '%'.$city.'%')
                ->orWhere('deals.address', 'like', '%'.$city.'%')));
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
