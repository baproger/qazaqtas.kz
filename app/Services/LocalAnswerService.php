<?php

namespace App\Services;

use App\Support\CurrentCompany;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Ответы на вопросы БЕЗ ИИ — прямо из базы.
 *
 * Большинство вопросов руководителя — это выборка, а не рассуждение:
 * «что просрочено», «сколько продал Ерман за месяц», «топ-5 клиентов»,
 * «остатки по складу». Такие ответы бесплатны, приходят мгновенно, не
 * требуют ключа и не выпускают данные компании наружу.
 *
 * Вопрос раскладывается на две части: ТЕМА (по корням слов, русский и
 * казахский) и РАМКИ — период, человек, город, «топ-N» (см. QuestionScope).
 * Тему не узнали — возвращаем null, и вызывающий решает, что делать.
 */
class LocalAnswerService
{
    /** Темы: корни слов → метод-обработчик. Порядок важен: кто раньше, тот и берёт. */
    private const TOPICS = [
        'overdue' => ['просроч', 'просрок', 'мерзім', 'кешік', 'дедлайн', 'опазда', 'горит', 'горящ'],
        'stock' => ['склад', 'остат', 'қойма', 'қалдық', 'запас'],
        'workshop' => ['цех', 'производ', 'өндір', 'изготов', 'заказ в работ'],
        'tasks' => ['задач', 'тапсырма', 'поручен'],
        // «денег» пишется без мягкого знака — корень 'деньг' его не ловит.
        'money_flow' => ['деньг', 'денег', 'ақша', 'оплат', 'платеж', 'платёж', 'төлем', 'выручк', 'поступлен', 'касс', 'доход'],
        'clients' => ['клиент', 'заказчик', 'покупател', 'тапсырыс беруші', 'контрагент'],
        'managers' => ['менеджер', 'сотрудник', 'кто больше', 'кто продал', 'кто лучш', 'персонал', 'қызметкер'],
        'products' => ['товар', 'продук', 'издели', 'что продаёт', 'что продает', 'ассортимент', 'өнім'],
        'deals' => ['сделк', 'мәміле', 'воронк', 'этап', 'продаж', 'сатылым', 'заказ'],
        'summary' => ['сводк', 'как дела', 'общая картина', 'итог', 'жалпы', 'қорытынды', 'всё сразу', 'все сразу'],
    ];

    /** Ответ из базы или null, если тема вопроса не распознана. */
    public function answer(string $question): ?string
    {
        $scope = QuestionScope::parse($question);
        $companyId = CurrentCompany::id();
        $q = mb_strtolower($question);

        foreach (self::TOPICS as $topic => $roots) {
            foreach ($roots as $root) {
                if (str_contains($q, $root)) {
                    $text = $this->{$topic}($companyId, $scope);

                    return $text !== '' ? $text : $this->nothing($topic, $scope);
                }
            }
        }

        // Темы нет, но человек назван — показываем сводку по нему:
        // «что у Ермана?» без слова «сделки» тоже должно работать.
        if ($scope->user) {
            return $this->person($companyId, $scope);
        }

        return null;
    }

    /** Что помощник умеет без ИИ — показываем, когда тема не распознана. */
    public function help(): string
    {
        return "Я работаю **без ИИ-ключа** и отвечаю по данным системы. Спросите, например:\n"
            ."- **Просроченные сделки** — что горит и у кого\n"
            ."- **Склад** — остатки и что ниже минимума\n"
            ."- **Цех** — заказы по этапам и что зависло\n"
            ."- **Задачи** — открытые и просроченные по людям\n"
            ."- **Деньги за месяц** — поступления и сравнение с прошлым\n"
            ."- **Сделки** — воронка по этапам с суммами\n"
            ."- **Топ-5 клиентов** — кто приносит больше всех\n"
            ."- **Кто больше продал** — рейтинг менеджеров\n"
            ."- **Какие товары продаются** — что уходит лучше\n"
            ."- **Сводка** — всё сразу одним экраном\n\n"
            ."К любому вопросу можно добавить рамки: **«за месяц»**, **«за неделю»**, "
            ."**«сегодня»**, имя сотрудника (**«что у Ермана»**) или город (**«по Шымкенту»**).\n\n"
            .'Свободные вопросы и деловые тексты станут доступны, когда администратор добавит бесплатный ключ Google Gemini: Настройки → ИИ-помощник.';
    }

    private function nothing(string $topic, QuestionScope $scope): string
    {
        $where = $scope->any() ? ' ('.$scope->label().')' : '';

        return match ($topic) {
            'overdue' => "**Просроченных сделок нет**{$where}. Все дедлайны в порядке.",
            'stock' => '**Склад пуст** — движений по остаткам ещё не было.',
            'workshop' => "**В цехе нет заказов**{$where}.",
            'tasks' => "**Открытых задач нет**{$where}.",
            'money_flow' => "**Поступлений денег нет**{$where}.",
            'clients' => "**Клиентов со сделками нет**{$where}.",
            'managers' => "**Продаж нет**{$where} — рейтинг строить не из чего.",
            'products' => "**Проданных товаров нет**{$where}.",
            default => "Данных по этой теме нет{$where}.",
        };
    }

    // ------------------------------------------------------------------
    // Общие заготовки запросов
    // ------------------------------------------------------------------

    /** Сделки с наложенными рамками вопроса. */
    private function deals_(?int $companyId, QuestionScope $s, bool $byPeriod = true): Builder
    {
        return DB::table('deals')
            ->whereNull('deals.deleted_at')
            ->when($companyId, fn ($q) => $q->where('deals.company_id', $companyId))
            ->when($s->user, fn ($q) => $q->where('deals.responsible_user_id', $s->user->id))
            ->when($s->city, fn ($q) => $q->where(fn ($w) => $w
                ->where('deals.branch', $s->city)
                ->orWhere('deals.address', 'like', '%'.$s->city.'%')))
            ->when($byPeriod && $s->from, fn ($q) => $q->whereBetween('deals.created_at', [$s->from, $s->to]));
    }

    /** Заголовок с рамками вопроса, если они были. */
    private function head(string $text, QuestionScope $s): string
    {
        return $s->any() ? "{$text} · _{$s->label()}_" : $text;
    }

    // ------------------------------------------------------------------
    // Темы
    // ------------------------------------------------------------------

    private function overdue(?int $companyId, QuestionScope $s): string
    {
        // Просрочка — это «сейчас», период вопроса к дате создания не применяем.
        $rows = $this->deals_($companyId, $s, byPeriod: false)
            ->join('deal_stages', 'deal_stages.id', '=', 'deals.deal_stage_id')
            ->leftJoin('users', 'users.id', '=', 'deals.responsible_user_id')
            ->where('deal_stages.is_won', false)
            ->whereNotNull('deals.deadline')
            ->whereDate('deals.deadline', '<', now()->toDateString())
            ->orderBy('deals.deadline')
            ->limit(20)
            ->get([
                'deals.number', 'deals.client_name', 'deals.company_name', 'deals.budget',
                'deals.deadline', 'deal_stages.name as stage', 'users.name as responsible',
            ]);

        if ($rows->isEmpty()) {
            return '';
        }

        $lines = $rows->map(function ($r) {
            $days = now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($r->deadline)->startOfDay());

            return "- **{$r->number}** {$this->who($r)} · {$this->money($r->budget)} · этап «{$r->stage}»"
                ." · просрочка **{$days} дн.** · ".($r->responsible ?: 'без ответственного');
        });

        return $this->head("**Просрочено сделок: {$rows->count()}** на {$this->money($rows->sum('budget'))}", $s)
            ."\n\n".$lines->implode("\n");
    }

    private function stock(?int $companyId, QuestionScope $s): string
    {
        $rows = DB::table('stock_movements')
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->whereNull('products.deleted_at')
            ->when($companyId, fn ($q) => $q->where('stock_movements.company_id', $companyId))
            ->groupBy('products.id', 'products.name', 'products.unit', 'products.min_stock')
            ->havingRaw('SUM(stock_movements.qty) <> 0')
            ->orderByRaw('SUM(stock_movements.qty) DESC')
            ->limit(40)
            ->get([
                'products.name', 'products.unit', 'products.min_stock',
                DB::raw('SUM(stock_movements.qty) as qty'),
            ]);

        if ($rows->isEmpty()) {
            return '';
        }

        $low = $rows->filter(fn ($r) => $r->min_stock !== null && (float) $r->qty < (float) $r->min_stock);

        $lines = $rows->map(function ($r) {
            $mark = $r->min_stock !== null && (float) $r->qty < (float) $r->min_stock ? '  ⚠️ ниже минимума' : '';

            return "- {$r->name}: **{$this->qty($r->qty)} {$r->unit}**{$mark}";
        });

        return "**Остатки на складе — {$rows->count()} позиц.**"
            .($low->isNotEmpty() ? " Ниже минимума: **{$low->count()}**." : ' Всё выше минимума.')
            ."\n\n".$lines->implode("\n");
    }

    private function workshop(?int $companyId, QuestionScope $s): string
    {
        $stages = DB::table('projects')
            ->join('project_stages', 'project_stages.id', '=', 'projects.project_stage_id')
            ->whereNull('projects.deleted_at')
            ->when($companyId, fn ($q) => $q->where('project_stages.company_id', $companyId))
            ->when($s->user, fn ($q) => $q->where('projects.responsible_user_id', $s->user->id))
            ->groupBy('project_stages.id', 'project_stages.name', 'project_stages.order')
            ->orderBy('project_stages.order')
            ->get(['project_stages.name as stage', DB::raw('COUNT(*) as cnt')]);

        if ($stages->isEmpty()) {
            return '';
        }

        $stuck = DB::table('projects')
            ->join('project_stages', 'project_stages.id', '=', 'projects.project_stage_id')
            ->leftJoin('deals', 'deals.id', '=', 'projects.deal_id')
            ->whereNull('projects.deleted_at')
            ->where('project_stages.is_completed', false)
            ->where('projects.created_at', '<', now()->subDays(3))
            ->when($companyId, fn ($q) => $q->where('project_stages.company_id', $companyId))
            ->when($s->user, fn ($q) => $q->where('projects.responsible_user_id', $s->user->id))
            ->orderBy('projects.created_at')
            ->limit(10)
            ->get(['projects.number', 'projects.created_at', 'deals.client_name', 'project_stages.name as stage']);

        $text = $this->head("**Цех — заказы по этапам** (всего {$stages->sum('cnt')})", $s)
            ."\n\n".$stages->map(fn ($r) => "- {$r->stage}: **{$r->cnt}**")->implode("\n");

        if ($stuck->isNotEmpty()) {
            $text .= "\n\n**Дольше 3 дней в работе: {$stuck->count()}**\n"
                .$stuck->map(function ($p) {
                    $days = now()->diffInDays(\Illuminate\Support\Carbon::parse($p->created_at));

                    return "- {$p->number} ".($p->client_name ?: '')." · этап «{$p->stage}» · **{$days} дн.**";
                })->implode("\n");
        }

        return $text;
    }

    private function tasks(?int $companyId, QuestionScope $s): string
    {
        $base = fn () => DB::table('tasks')
            ->whereNull('tasks.deleted_at')
            ->where('tasks.status', '!=', 'done')
            ->when($s->user, fn ($q) => $q->where('tasks.assignee_id', $s->user->id));

        $open = $base()->count();

        if ($open === 0) {
            return '';
        }

        $overdue = $base()
            ->leftJoin('users', 'users.id', '=', 'tasks.assignee_id')
            ->whereNotNull('tasks.due_date')
            ->whereDate('tasks.due_date', '<', now()->toDateString())
            ->orderBy('tasks.due_date')
            ->limit(15)
            ->get(['tasks.title', 'tasks.due_date', 'users.name as assignee']);

        $text = $this->head("**Открытых задач: {$open}**, просрочено: **{$overdue->count()}**", $s);

        if (! $s->user) {
            $byUser = $base()
                ->join('users', 'users.id', '=', 'tasks.assignee_id')
                ->groupBy('users.id', 'users.name')
                ->orderByRaw('COUNT(*) DESC')
                ->limit($s->limit)
                ->get(['users.name', DB::raw('COUNT(*) as cnt')]);

            $text .= "\n\nПо ответственным:\n"
                .$byUser->map(fn ($u) => "- {$u->name}: **{$u->cnt}**")->implode("\n");
        }

        if ($overdue->isNotEmpty()) {
            $text .= "\n\n**Просроченные:**\n"
                .$overdue->map(fn ($t) => '- '.$t->title.' · '
                    .\Illuminate\Support\Carbon::parse($t->due_date)->format('d.m.Y')
                    .' · '.($t->assignee ?: 'без ответственного'))->implode("\n");
        }

        return $text;
    }

    /** Поступления денег: спрошенный период против такого же прошлого. */
    private function money_flow(?int $companyId, QuestionScope $s): string
    {
        // payment_date хранит дату со временем: сравнение с «ГГГГ-ММ-ДД»
        // отсекало бы платежи последнего дня — берём границы суток.
        $sum = fn ($from, $to) => (float) DB::table('payments')
            ->whereBetween('payment_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->sum('amount');

        $now = now();
        $from = $s->from ?? $now->copy()->startOfMonth();
        $to = $s->to ?? $now;
        $label = $s->periodLabel ?? 'за текущий месяц';

        // Тот же по длине отрезок, сдвинутый назад, — честное сравнение.
        $length = $from->diffInDays($to);
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($length);

        $cur = $sum($from, $to);
        $prev = $sum($prevFrom, $prevTo);

        if ($cur <= 0 && $prev <= 0) {
            return '';
        }

        $delta = $prev > 0 ? round(($cur - $prev) / $prev * 100) : null;
        $sign = $delta === null ? '' : ($delta >= 0 ? " · рост на **{$delta}%**" : ' · падение на **'.abs($delta).'%**');

        return "**Поступления денег {$label}: {$this->money($cur)}**{$sign}\n\n"
            ."- Период: {$from->format('d.m.Y')} — {$to->format('d.m.Y')}\n"
            ."- Предыдущий такой же отрезок: {$this->money($prev)} ({$prevFrom->format('d.m')} — {$prevTo->format('d.m')})";
    }

    private function deals(?int $companyId, QuestionScope $s): string
    {
        $rows = $this->deals_($companyId, $s)
            ->join('deal_stages', 'deal_stages.id', '=', 'deals.deal_stage_id')
            ->groupBy('deal_stages.id', 'deal_stages.name', 'deal_stages.order')
            ->orderBy('deal_stages.order')
            ->get(['deal_stages.name as stage', DB::raw('COUNT(*) as cnt'), DB::raw('COALESCE(SUM(deals.budget), 0) as total')]);

        if ($rows->isEmpty()) {
            return '';
        }

        $count = $rows->sum('cnt');
        $total = $rows->sum('total');
        $avg = $count > 0 ? $total / $count : 0;

        return $this->head("**Сделки: {$count} на {$this->money($total)}**", $s)
            ."\n\n".$rows->map(fn ($r) => "- {$r->stage}: **{$r->cnt}** шт · {$this->money($r->total)}")->implode("\n")
            ."\n\nСредняя сделка: **{$this->money($avg)}**";
    }

    /** Топ клиентов по сумме сделок. */
    private function clients(?int $companyId, QuestionScope $s): string
    {
        $rows = $this->deals_($companyId, $s)
            ->selectRaw("COALESCE(NULLIF(deals.client_name, ''), NULLIF(deals.company_name, ''), '—') as client")
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(deals.budget), 0) as total')
            ->groupBy('client')
            ->orderByRaw('SUM(deals.budget) DESC')
            ->limit($s->limit)
            ->get();

        if ($rows->isEmpty()) {
            return '';
        }

        return $this->head("**Клиенты по сумме сделок — топ-{$rows->count()}**", $s)
            ."\n\n".$rows->map(fn ($r, $i) => ($i + 1).". **{$r->client}** — {$this->money($r->total)} ({$r->cnt} сдел.)")->implode("\n");
    }

    /** Рейтинг менеджеров по сумме их сделок. */
    private function managers(?int $companyId, QuestionScope $s): string
    {
        $rows = $this->deals_($companyId, $s)
            ->join('users', 'users.id', '=', 'deals.responsible_user_id')
            ->groupBy('users.id', 'users.name')
            ->orderByRaw('SUM(deals.budget) DESC')
            ->limit($s->limit)
            ->get(['users.name', DB::raw('COUNT(*) as cnt'), DB::raw('COALESCE(SUM(deals.budget), 0) as total')]);

        if ($rows->isEmpty()) {
            return '';
        }

        return $this->head('**Менеджеры по сумме сделок**', $s)
            ."\n\n".$rows->map(fn ($r, $i) => ($i + 1).". **{$r->name}** — {$this->money($r->total)} ({$r->cnt} сдел.)")->implode("\n");
    }

    /** Что продаётся: позиции сделок по объёму и сумме. */
    private function products(?int $companyId, QuestionScope $s): string
    {
        $rows = $this->deals_($companyId, $s)
            ->join('deal_items', 'deal_items.deal_id', '=', 'deals.id')
            ->groupBy('deal_items.name', 'deal_items.unit')
            ->orderByRaw('SUM(deal_items.amount) DESC')
            ->limit($s->limit)
            ->get([
                'deal_items.name', 'deal_items.unit',
                DB::raw('SUM(deal_items.quantity) as qty'),
                DB::raw('COALESCE(SUM(deal_items.amount), 0) as total'),
            ]);

        if ($rows->isEmpty()) {
            return '';
        }

        return $this->head('**Товары в сделках — что уходит лучше**', $s)
            ."\n\n".$rows->map(fn ($r, $i) => ($i + 1).". **{$r->name}** — {$this->qty($r->qty)} {$r->unit} на {$this->money($r->total)}")->implode("\n");
    }

    /** «Что у Ермана?» — короткая сводка по человеку. */
    private function person(?int $companyId, QuestionScope $s): string
    {
        $blocks = array_filter([
            $this->deals($companyId, $s),
            $this->overdue($companyId, $s),
            $this->tasks($companyId, $s),
        ]);

        if (! $blocks) {
            return "По сотруднику **{$s->user->name}** данных нет"
                .($s->periodLabel ? " {$s->periodLabel}" : '').'.';
        }

        return implode("\n\n", $blocks);
    }

    /** Всё сразу: короткая выжимка по каждому разделу. */
    private function summary(?int $companyId, QuestionScope $s): string
    {
        return implode("\n\n", array_filter([
            $this->deals($companyId, $s),
            $this->workshop($companyId, $s),
            $this->stock($companyId, $s),
            $this->tasks($companyId, $s),
            $this->money_flow($companyId, $s),
        ]));
    }

    // ------------------------------------------------------------------
    // Форматирование
    // ------------------------------------------------------------------

    private function who(object $row): string
    {
        return $row->client_name ?: $row->company_name ?: '—';
    }

    private function money(float|int|string|null $v): string
    {
        return number_format((float) $v, 0, ',', ' ').' ₸';
    }

    private function qty(float|int|string $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
    }
}
