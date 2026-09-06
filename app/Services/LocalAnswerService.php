<?php

namespace App\Services;

use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Ответы на вопросы БЕЗ ИИ — прямо из базы.
 *
 * Помощнику не всегда нужна модель: «что по складу», «покажи просрочку»,
 * «сколько денег за месяц» — это готовые выборки. Такой ответ бесплатен,
 * приходит мгновенно и работает без ANTHROPIC_API_KEY, поэтому он же служит
 * запасным вариантом, когда ИИ недоступен.
 *
 * Вопрос разбирается по ключевым словам (русский и казахский). Не узнали
 * тему — возвращаем null, и вызывающий решает, что делать дальше.
 */
class LocalAnswerService
{
    /** Темы: набор корней слов → метод-обработчик. */
    private const TOPICS = [
        'overdue' => ['просроч', 'просрок', 'мерзім', 'кешік', 'дедлайн', 'опазда'],
        'stock' => ['склад', 'остат', 'қойма', 'қалдық', 'запас'],
        'workshop' => ['цех', 'производ', 'өндір', 'изготов', 'заказ в работ'],
        'tasks' => ['задач', 'тапсырма', 'поручен'],
        // «денег» пишется без мягкого знака — корень 'деньг' его не ловит.
        'money_flow' => ['деньг', 'денег', 'ақша', 'оплат', 'платеж', 'платёж', 'төлем', 'выручк', 'поступлен', 'касс', 'доход'],
        'deals' => ['сделк', 'мәміле', 'воронк', 'этап', 'продаж', 'сатылым'],
        'summary' => ['сводк', 'как дела', 'общая картина', 'итог', 'жалпы', 'қорытынды', 'всё сразу'],
    ];

    /** Ответ из базы или null, если тема вопроса не распознана. */
    public function answer(string $question): ?string
    {
        $q = mb_strtolower($question);

        foreach (self::TOPICS as $topic => $roots) {
            foreach ($roots as $root) {
                if (str_contains($q, $root)) {
                    $text = $this->{$topic}(CurrentCompany::id());

                    return $text !== '' ? $text : $this->nothing($topic);
                }
            }
        }

        return null;
    }

    /** Что помощник умеет без ИИ — показываем, когда тема не распознана. */
    public function help(): string
    {
        return "Я работаю **без ИИ-ключа** и отвечаю по данным системы. Спросите одно из:\n"
            ."- **Просроченные сделки** — что горит и у кого\n"
            ."- **Склад** — остатки и что ниже минимума\n"
            ."- **Цех** — заказы по этапам и что зависло\n"
            ."- **Задачи** — открытые и просроченные по людям\n"
            ."- **Деньги** — поступления за месяц и сравнение с прошлым\n"
            ."- **Сделки** — воронка по этапам с суммами\n"
            ."- **Сводка** — всё сразу одним экраном\n\n"
            .'Свободные вопросы и деловые тексты станут доступны, когда администратор добавит ключ ANTHROPIC_API_KEY.';
    }

    private function nothing(string $topic): string
    {
        return match ($topic) {
            'overdue' => '**Просроченных сделок нет.** Все дедлайны в порядке.',
            'stock' => '**Склад пуст** — движений по остаткам ещё не было.',
            'workshop' => '**В цехе нет заказов.**',
            'tasks' => '**Открытых задач нет.**',
            'money_flow' => '**Поступлений денег пока не зафиксировано.**',
            default => 'Данных по этой теме в системе пока нет.',
        };
    }

    // ------------------------------------------------------------------
    // Темы
    // ------------------------------------------------------------------

    private function overdue(?int $companyId): string
    {
        $rows = DB::table('deals')
            ->join('deal_stages', 'deal_stages.id', '=', 'deals.deal_stage_id')
            ->leftJoin('users', 'users.id', '=', 'deals.responsible_user_id')
            ->whereNull('deals.deleted_at')
            ->where('deal_stages.is_won', false)
            ->whereNotNull('deals.deadline')
            ->whereDate('deals.deadline', '<', now()->toDateString())
            ->when($companyId, fn ($q) => $q->where('deals.company_id', $companyId))
            ->orderBy('deals.deadline')
            ->limit(20)
            ->get([
                'deals.number', 'deals.client_name', 'deals.company_name', 'deals.budget',
                'deals.deadline', 'deal_stages.name as stage', 'users.name as responsible',
            ]);

        if ($rows->isEmpty()) {
            return '';
        }

        $total = $rows->sum('budget');
        $lines = $rows->map(function ($r) {
            $days = now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($r->deadline)->startOfDay());
            $who = $r->client_name ?: $r->company_name ?: '—';

            return "- **{$r->number}** {$who} · {$this->money($r->budget)} · этап «{$r->stage}»"
                ." · просрочка **{$days} дн.** · ".($r->responsible ?: 'без ответственного');
        });

        return "**Просрочено сделок: {$rows->count()}** на {$this->money($total)}\n\n".$lines->implode("\n");
    }

    private function stock(?int $companyId): string
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

        $head = "**Остатки на складе — {$rows->count()} позиц.**"
            .($low->isNotEmpty() ? " Ниже минимума: **{$low->count()}**." : ' Всё выше минимума.');

        return $head."\n\n".$lines->implode("\n");
    }

    private function workshop(?int $companyId): string
    {
        $stages = DB::table('projects')
            ->join('project_stages', 'project_stages.id', '=', 'projects.project_stage_id')
            ->whereNull('projects.deleted_at')
            ->when($companyId, fn ($q) => $q->where('project_stages.company_id', $companyId))
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
            ->orderBy('projects.created_at')
            ->limit(10)
            ->get(['projects.number', 'projects.created_at', 'deals.client_name', 'project_stages.name as stage']);

        $text = "**Цех — заказы по этапам** (всего {$stages->sum('cnt')})\n\n"
            .$stages->map(fn ($s) => "- {$s->stage}: **{$s->cnt}**")->implode("\n");

        if ($stuck->isNotEmpty()) {
            $text .= "\n\n**Дольше 3 дней в работе: {$stuck->count()}**\n"
                .$stuck->map(function ($p) {
                    $days = now()->diffInDays(\Illuminate\Support\Carbon::parse($p->created_at));

                    return "- {$p->number} ".($p->client_name ?: '')." · этап «{$p->stage}» · **{$days} дн.**";
                })->implode("\n");
        }

        return $text;
    }

    /** Задачи в системе не разделены по фирмам — $companyId здесь не нужен. */
    private function tasks(?int $companyId = null): string
    {
        $open = DB::table('tasks')->whereNull('deleted_at')->where('status', '!=', 'done')->count();

        if ($open === 0) {
            return '';
        }

        $overdue = DB::table('tasks')
            ->leftJoin('users', 'users.id', '=', 'tasks.assignee_id')
            ->whereNull('tasks.deleted_at')
            ->where('tasks.status', '!=', 'done')
            ->whereNotNull('tasks.due_date')
            ->whereDate('tasks.due_date', '<', now()->toDateString())
            ->orderBy('tasks.due_date')
            ->limit(15)
            ->get(['tasks.title', 'tasks.due_date', 'users.name as assignee']);

        $byUser = DB::table('tasks')
            ->join('users', 'users.id', '=', 'tasks.assignee_id')
            ->whereNull('tasks.deleted_at')
            ->where('tasks.status', '!=', 'done')
            ->groupBy('users.id', 'users.name')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(10)
            ->get(['users.name', DB::raw('COUNT(*) as cnt')]);

        $text = "**Открытых задач: {$open}**, просрочено: **{$overdue->count()}**\n\n"
            .'По ответственным:'."\n"
            .$byUser->map(fn ($u) => "- {$u->name}: **{$u->cnt}**")->implode("\n");

        if ($overdue->isNotEmpty()) {
            $text .= "\n\n**Просроченные:**\n"
                .$overdue->map(fn ($t) => '- '.$t->title.' · '
                    .\Illuminate\Support\Carbon::parse($t->due_date)->format('d.m.Y')
                    .' · '.($t->assignee ?: 'без ответственного'))->implode("\n");
        }

        return $text;
    }

    private function money(float|int|string|null $v = null): string
    {
        return number_format((float) $v, 0, ',', ' ').' ₸';
    }

    private function qty(float|int|string $v): string
    {
        $s = number_format((float) $v, 2, ',', ' ');

        return rtrim(rtrim($s, '0'), ',');
    }

    private function deals(?int $companyId): string
    {
        $rows = DB::table('deals')
            ->join('deal_stages', 'deal_stages.id', '=', 'deals.deal_stage_id')
            ->whereNull('deals.deleted_at')
            ->when($companyId, fn ($q) => $q->where('deals.company_id', $companyId))
            ->groupBy('deal_stages.id', 'deal_stages.name', 'deal_stages.order')
            ->orderBy('deal_stages.order')
            ->get(['deal_stages.name as stage', DB::raw('COUNT(*) as cnt'), DB::raw('COALESCE(SUM(deals.budget), 0) as total')]);

        if ($rows->isEmpty()) {
            return '';
        }

        return "**Воронка сделок** — всего {$rows->sum('cnt')} на {$this->money($rows->sum('total'))}\n\n"
            .$rows->map(fn ($r) => "- {$r->stage}: **{$r->cnt}** шт · {$this->money($r->total)}")->implode("\n");
    }

    /** Поступления денег: текущий месяц против прошлого (касса общая). */
    private function money_flow(?int $companyId = null): string
    {
        $sum = fn ($from, $to) => (float) DB::table('payments')
            ->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])
            ->sum('amount');

        $now = now();
        $cur = $sum($now->copy()->startOfMonth(), $now);
        $prevFull = $sum($now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth());
        $prevSame = $sum($now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow());

        if ($cur <= 0 && $prevFull <= 0) {
            return '';
        }

        $delta = $prevSame > 0 ? round(($cur - $prevSame) / $prevSame * 100) : null;
        $sign = $delta === null ? '' : ($delta >= 0 ? "рост на {$delta}%" : 'падение на '.abs($delta).'%');

        return "**Поступления денег**\n\n"
            ."- Текущий месяц: **{$this->money($cur)}**\n"
            ."- На ту же дату прошлого месяца: {$this->money($prevSame)}".($sign ? " · {$sign}" : '')."\n"
            ."- Прошлый месяц целиком: {$this->money($prevFull)}";
    }

    /** Всё сразу: короткая выжимка по каждому разделу. */
    private function summary(?int $companyId): string
    {
        $blocks = array_filter([
            $this->deals($companyId),
            $this->workshop($companyId),
            $this->stock($companyId),
            $this->tasks($companyId),
            $this->money_flow($companyId),
        ]);

        return implode("\n\n", $blocks);
    }
}
