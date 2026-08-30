<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\DealStageLog;
use App\Models\StageRobotRun;
use App\Models\Task;
use App\Support\AccessScope;
use App\Support\CurrentCompany;
use App\Support\LiveStamp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /** Подписи типов уведомлений — для фильтра на странице. */
    public const TYPES = [
        'deal_stage_changed' => 'Этап сделки', 'task_assigned' => 'Задача назначена', 'task_overdue' => 'Задача просрочена',
        'department_task_overdue' => 'Просрочка в отделе', 'expense_pending' => 'Расход ждёт подтверждения', 'expense_confirmed' => 'Расход подтверждён',
        'expense_handled' => 'Расход обработан', 'expense_threshold' => 'Превышен лимит расходов', 'company_expense_submitted' => 'Заявка на расход',
        'company_expense_paid' => 'Заявка оплачена', 'company_expense_stale' => 'Заявка зависла', 'finance_deleted' => 'Удалена финансовая запись',
        'product_shortage' => 'Нехватка на складе', 'production_plan_queued' => 'План производства', 'site_order' => 'Заказ с сайта',
        'chat_mention' => 'Упоминание в чате', 'birthday' => 'День рождения', 'robot' => 'Робот этапа',
    ];

    /**
     * Страница уведомлений: полный список с деталями + лента событий по
     * сделкам, которые человек видит (переходы этапов, задачи, роботы).
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $type = $request->string('type')->toString();
        $only = $request->string('only')->toString(); // unread | ''

        $notifications = $user->notifications()
            ->when($type !== '' && $type !== 'all', fn ($q) => $q->where('data->type', $type))
            ->when($only === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->latest()->paginate(40)->withQueryString()
            ->through(fn ($n) => [
                'id' => $n->id, 'type' => $n->data['type'] ?? null, 'typeLabel' => self::TYPES[$n->data['type'] ?? ''] ?? 'Уведомление',
                'title' => $n->data['title'] ?? '', 'message' => $n->data['message'] ?? '',
                'url' => $n->data['url'] ?? (isset($n->data['deal_id']) ? route('deals.show', $n->data['deal_id']) : null),
                'deal_number' => $n->data['deal_number'] ?? null, 'data' => $n->data,
                'read_at' => $n->read_at?->toIso8601String(), 'created_at' => $n->created_at->toIso8601String(),
            ]);

        // Типы, которые реально приходили этому человеку, — для фильтра.
        $types = $user->notifications()->get(['data'])->map(fn ($n) => $n->data['type'] ?? null)->filter()->countBy()
            ->map(fn ($n, $t) => ['value' => $t, 'label' => self::TYPES[$t] ?? $t, 'count' => $n])->values();

        // Лента событий: сделки в области видимости человека (та же логика,
        // что у списка сделок), последние 150 переходов + задачи + роботы.
        $dealIds = AccessScope::apply(Deal::query()->when(CurrentCompany::id(), fn ($q, $c) => $q->where('company_id', $c)), $user, 'deal.viewAny')
            ->when(! $user->can('deal.viewAny'), fn ($q) => $q->where(fn ($w) => $w->where('responsible_user_id', $user->id)->orWhere('foreman_id', $user->id)))
            ->pluck('id');

        $stageEvents = DealStageLog::with(['deal:id,number,company_name', 'mover:id,name'])
            ->whereIn('deal_id', $dealIds)->latest('entered_at')->limit(150)->get()
            ->map(fn ($l) => ['kind' => 'stage', 'at' => $l->entered_at?->toIso8601String(), 'deal' => $l->deal ? ['id' => $l->deal->id, 'number' => $l->deal->number, 'company' => $l->deal->company_name] : null,
                'title' => 'Этап «'.$l->stage_name.'»', 'who' => $l->mover?->name, 'detail' => $l->left_at ? 'находилась '.$this->human((int) $l->duration_seconds) : 'сейчас на этом этапе']);

        $taskEvents = Task::with(['assignee:id,name', 'creator:id,name'])
            ->where('taskable_type', 'deal')->whereIn('taskable_id', $dealIds)->latest()->limit(100)->get()
            ->map(fn ($t) => ['kind' => 'task', 'at' => $t->created_at?->toIso8601String(), 'deal' => ['id' => $t->taskable_id, 'number' => null, 'company' => null],
                'title' => 'Задача: '.$t->title, 'who' => $t->creator?->name, 'detail' => ($t->assignee ? 'исполнитель '.$t->assignee->name.' · ' : '').'статус '.$t->status.($t->due_date ? ' · срок '.$t->due_date->format('d.m.Y') : '')]);

        $robotEvents = StageRobotRun::with(['robot:id,name', 'deal:id,number,company_name'])
            ->whereIn('deal_id', $dealIds)->latest('id')->limit(100)->get()
            ->map(fn ($r) => ['kind' => 'robot', 'at' => ($r->finished_at ?? $r->created_at)?->toIso8601String(), 'deal' => $r->deal ? ['id' => $r->deal->id, 'number' => $r->deal->number, 'company' => $r->deal->company_name] : null,
                'title' => 'Робот «'.($r->robot?->name ?? '—').'»', 'who' => 'Система', 'detail' => match ($r->status) {
                    'done' => 'выполнен', 'skipped' => 'пропущен: '.$r->error, 'failed' => 'ошибка: '.$r->error, 'queued', 'waiting' => 'запланирован на '.$r->scheduled_at?->format('d.m H:i'), default => $r->status
                }, 'status' => $r->status]);

        $dealNumbers = Deal::whereIn('id', $taskEvents->pluck('deal.id')->filter()->unique())->get(['id', 'number', 'company_name'])->keyBy('id');
        $taskEvents = $taskEvents->map(function ($e) use ($dealNumbers) {
            $d = $dealNumbers[$e['deal']['id']] ?? null;
            $e['deal'] = $d ? ['id' => $d->id, 'number' => $d->number, 'company' => $d->company_name] : null;

            return $e;
        });

        $events = $stageEvents->concat($taskEvents)->concat($robotEvents)->sortByDesc('at')->values()->take(200);

        return Inertia::render('Notifications/Index', [
            'notifications' => $notifications,
            'types' => $types,
            'filters' => ['type' => $type ?: 'all', 'only' => $only],
            'unread' => $user->unreadNotifications()->count(),
            'events' => $events,
        ]);
    }

    private function human(int $sec): string
    {
        if ($sec < 3600) {
            return max(1, intdiv($sec, 60)).' мин';
        }
        if ($sec < 86400) {
            return intdiv($sec, 3600).' ч';
        }

        return intdiv($sec, 86400).' дн';
    }

    public function markRead(Request $request, string $id): RedirectResponse
    {
        $request->user()->notifications()->where('id', $id)->update(['read_at' => now()]);
        LiveStamp::bump($request->user()->id, 'notifications');

        return back();
    }

    /**
     * `silent` — автоотметка при открытии колокольчика: сообщение об успехе
     * тут не нужно, человек ничего не нажимал осознанно.
     */
    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();
        LiveStamp::bump($request->user()->id, 'notifications');

        return $request->boolean('silent')
            ? back()
            : back()->with('success', 'Все уведомления прочитаны.');
    }
}
