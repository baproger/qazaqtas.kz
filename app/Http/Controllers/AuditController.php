<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
    /** Русские названия таблиц журнала. */
    private const TABLE_LABELS = [
        'deals' => 'Сделки', 'projects' => 'Заказы цеха', 'tasks' => 'Задачи',
        'invoices' => 'Счета', 'payments' => 'Платежи', 'expenses' => 'Расходы',
        'cash_receipts' => 'Поступления денег', 'debts' => 'Задолженности',
        'payroll_adjustments' => 'Корректировки ЗП', 'dds_entries' => 'ДДС',
        'users' => 'Сотрудники', 'departments' => 'Отделы', 'clients' => 'Клиенты',
        'documents' => 'Документы', 'materials' => 'Склад', 'material_receipts' => 'Приход склада',
        'pre_deals' => 'Предв. сделки', 'chats' => 'Чаты', 'chat_messages' => 'Сообщения чата',
        'comments' => 'Комментарии', 'settings' => 'Настройки', 'deal_stages' => 'Этапы сделок',
        'project_stages' => 'Этапы цеха', 'expense_categories' => 'Категории расходов',
        'workshop_screens' => 'ТВ-экраны',
    ];

    /** Русские названия полей. */
    private const FIELD_LABELS = [
        'status' => 'Статус', 'deal_stage_id' => 'Этап', 'project_stage_id' => 'Этап цеха',
        'amount' => 'Сумма', 'budget' => 'Сумма договора', 'payment_method' => 'Способ оплаты',
        'bonus_rate_override' => 'Ручной % бонуса', 'responsible_user_id' => 'Ответственный',
        'assignee_id' => 'Исполнитель', 'department_id' => 'Отдел', 'client_id' => 'Клиент',
        'category_id' => 'Категория', 'material_id' => 'Материал', 'qty' => 'Количество',
        'name' => 'Название', 'title' => 'Заголовок', 'description' => 'Описание', 'note' => 'Заметка',
        'number' => 'Номер', 'bin' => '№ договора / БИН', 'address' => 'Адрес',
        'company_name' => 'Заказчик', 'client_name' => 'Товар', 'lot_number' => 'Кол-во (лот)',
        'unit' => 'Ед. изм.', 'source' => 'Источник', 'deadline' => 'Срок',
        'contract_date' => 'Дата договора', 'issue_date' => 'Дата счёта', 'due_date' => 'Срок оплаты',
        'date' => 'Дата', 'closed_at' => 'Закрыта', 'completed_at' => 'Завершена',
        'confirmed_at' => 'Подтверждён', 'started_at' => 'Начат',
        'salary' => 'Оклад', 'phone' => 'Телефон', 'email' => 'Email',
        'birth_date' => 'День рождения', 'hired_at' => 'Дата приёма', 'head_user_id' => 'Руководитель',
        'is_active' => 'Активен', 'is_completed' => 'Завершающий', 'is_won' => 'Успешный этап',
        'type' => 'Тип', 'kind' => 'Вид', 'priority' => 'Приоритет', 'days' => 'Дней',
        'balance' => 'Фактический остаток', 'receivable' => 'Дебиторский', 'bank' => 'Банк',
        'workshop' => 'Цех', 'avatar' => 'Фото', 'language' => 'Язык', 'order' => 'Порядок',
        'color' => 'Цвет', 'pinned_message_id' => 'Закреплённое сообщение', 'expense_id' => 'Расход',
        'price' => 'Цена', 'quantity' => 'Остаток', 'message' => 'Сообщение',
        'file_path' => 'Файл', 'contract_path' => 'Договор (файл)', 'company_id' => 'Фирма',
        'stage_type' => 'Тип этапа',
    ];

    /** Русские значения (по полю). */
    private const VALUE_MAPS = [
        'status' => [
            'draft' => 'Черновик', 'sent' => 'Выставлен', 'partial' => 'Частично оплачен',
            'paid' => 'Оплачен', 'cancelled' => 'Отменён', 'active' => 'Активна',
            'closed' => 'Закрыта', 'new' => 'Новая', 'todo' => 'К выполнению',
            'in_progress' => 'В работе', 'review' => 'Проверка', 'done' => 'Готово',
            'pending' => 'Ожидает', 'confirmed' => 'Подтверждён', 'completed' => 'Завершён',
        ],
        'payment_method' => ['cash' => 'Наличные', 'bank' => 'Банк'],
        'type' => [
            'absence' => 'Отгул', 'sick' => 'Больничный', 'fine' => 'Штраф',
            'advance' => 'Аванс', 'bonus' => 'Премия', 'direct' => 'Прямой',
            'material' => 'Материальный', 'other' => 'Прочий',
            'personal' => 'Личный', 'group' => 'Группа', 'global' => 'Общий',
            'receivable' => 'Дебиторка', 'payable' => 'Кредиторка',
        ],
        'kind' => ['account' => 'Счёт компании', 'debt' => 'Долг', 'workshop' => 'Цех', 'office' => 'Офис'],
        'priority' => ['low' => 'Низкий', 'medium' => 'Средний', 'high' => 'Высокий', 'urgent' => 'Срочный'],
        'is_active' => ['1' => 'Да', '0' => 'Нет', 'true' => 'Да', 'false' => 'Нет'],
        'is_completed' => ['1' => 'Да', '0' => 'Нет'],
        'is_won' => ['1' => 'Да', '0' => 'Нет'],
    ];

    /** Денежные поля — форматируем с разрядами. */
    private const MONEY_FIELDS = ['amount', 'budget', 'salary', 'balance', 'receivable', 'price'];

    public function index(Request $request): Response
    {
        // Только админ: журнал — generic-таблица (diff всех сущностей обеих
        // фирм, включая зарплаты/бюджеты), корректно разделить по компаниям
        // нельзя, поэтому доступ у глобального владельца (admin), а не у
        // директора/бухгалтера, привязанных к одной фирме.
        abort_unless($request->user()->hasRole('admin'), 403);

        $logs = AuditLog::query()
            ->with('user:id,name')
            ->when($request->string('table')->toString(), fn ($q, $t) => $q->where('table_name', $t))
            ->when($request->string('action')->toString(), fn ($q, $a) => $q->where('action', $a))
            ->when($request->integer('user'), fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->date('from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->date('to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        // Сырые id внешних ключей → имена (этап, сотрудник, клиент…).
        $logs->setCollection(\App\Support\AuditFormatter::humanize($logs->getCollection(), [
            'deal_stage_id' => \App\Models\DealStage::pluck('name', 'id'),
            'project_stage_id' => \App\Models\ProjectStage::pluck('name', 'id'),
            'responsible_user_id' => \App\Models\User::pluck('name', 'id'),
            'assignee_id' => \App\Models\User::pluck('name', 'id'),
            'head_user_id' => \App\Models\User::pluck('name', 'id'),
            'department_id' => \App\Models\Department::pluck('name', 'id'),
            'client_id' => \App\Models\Client::pluck('name', 'id'),
            'category_id' => \App\Models\ExpenseCategory::pluck('name', 'id'),
            'material_id' => \App\Models\Material::pluck('name', 'id'),
            'company_id' => \App\Models\Company::pluck('name', 'id'),
        ]));

        // Связанная СДЕЛКА каждой строки: расход/счёт/платёж/заказ цеха/лот/задача →
        // ссылка «QT-088», чтобы видно было, по какой сделке действие (батчем, без N+1).
        $col = $logs->getCollection();
        $ids = fn (string $t) => $col->where('table_name', $t)->pluck('record_id')->filter()->unique()->values();
        $dealByRecord = [
            'deals' => $ids('deals')->mapWithKeys(fn ($id) => [$id => $id]),
            'expenses' => \App\Models\Expense::whereIn('id', $ids('expenses'))->where('expenseable_type', 'deal')->pluck('expenseable_id', 'id'),
            'invoices' => \App\Models\Invoice::whereIn('id', $ids('invoices'))->where('invoiceable_type', 'deal')->pluck('invoiceable_id', 'id'),
            'payments' => \App\Models\Payment::whereIn('payments.id', $ids('payments'))
                ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')->where('invoices.invoiceable_type', 'deal')
                ->pluck('invoices.invoiceable_id', 'payments.id'),
            'projects' => \App\Models\Project::whereIn('id', $ids('projects'))->whereNotNull('deal_id')->pluck('deal_id', 'id'),
            'pre_deals' => \App\Models\PreDeal::whereIn('id', $ids('pre_deals'))->whereNotNull('deal_id')->pluck('deal_id', 'id'),
            'tasks' => \App\Models\Task::whereIn('id', $ids('tasks'))->where('taskable_type', 'deal')->pluck('taskable_id', 'id'),
        ];
        // withTrashed: у удалённой сделки номер показываем, но серым (без ссылки).
        $dealInfo = \App\Models\Deal::withTrashed()
            ->whereIn('id', collect($dealByRecord)->flatMap(fn ($m) => $m->values())->unique()->values())
            ->get(['id', 'number', 'deleted_at'])->keyBy('id');

        // Всё остальное — по-русски: таблица, поле, значения, даты, деньги.
        $logs->setCollection($logs->getCollection()->map(function ($log) use ($dealByRecord, $dealInfo) {
            $dealId = $dealByRecord[$log->table_name][$log->record_id] ?? null;
            $deal = $dealId ? $dealInfo[$dealId] ?? null : null;

            return [
            'deal' => $deal ? [
                'id' => $deal->id,
                'number' => $deal->number,
                'deleted' => $deal->deleted_at !== null,
            ] : null,
            'id' => $log->id,
            'created_at' => $log->created_at?->toIso8601String(),
            'user' => $log->user?->name,
            'ip' => $log->ip,
            'table' => self::TABLE_LABELS[$log->table_name] ?? $log->table_name,
            'record_id' => $log->record_id,
            // Кликабельная запись — там, где есть своя страница.
            'link' => $log->record_id ? match ($log->table_name) {
                'deals' => route('deals.show', $log->record_id),
                'projects' => route('projects.show', $log->record_id),
                'users' => route('users.show', $log->record_id),
                default => null,
            } : null,
            'action' => $log->action,
            'field' => $log->field_name ? (self::FIELD_LABELS[$log->field_name] ?? $log->field_name) : null,
            'old' => $this->formatValue($log->field_name, $log->old_value),
            'new' => $this->formatValue($log->field_name, $log->new_value),
            ];
        }));

        return Inertia::render('Audit/Index', [
            'logs' => $logs,
            'filters' => $request->only('table', 'action', 'user', 'from', 'to'),
            'tables' => AuditLog::query()->distinct()->orderBy('table_name')->pluck('table_name')
                ->map(fn ($t) => ['value' => $t, 'label' => self::TABLE_LABELS[$t] ?? $t])->values(),
            'users' => \App\Models\User::whereIn('id', AuditLog::distinct()->pluck('user_id')->filter())
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Значение по-русски: словарь, дата — d.m.Y, деньги — с разрядами. */
    private function formatValue(?string $field, ?string $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if ($field && isset(self::VALUE_MAPS[$field][$v])) {
            return self::VALUE_MAPS[$field][$v];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $v)) {
            return Carbon::parse($v)->format('d.m.Y H:i');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return Carbon::parse($v)->format('d.m.Y');
        }
        if ($field && in_array($field, self::MONEY_FIELDS, true) && is_numeric($v)) {
            return number_format((float) $v, 0, ',', ' ').' ₸';
        }

        return $v;
    }
}
