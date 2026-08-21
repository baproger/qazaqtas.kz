<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Brigade;
use App\Models\Client;
use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Department;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Material;
use App\Models\Payment;
use App\Models\PreDeal;
use App\Models\Project;
use App\Models\ProjectStage;
use App\Models\Task;
use App\Models\User;
use App\Support\AuditFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
        'work_orders' => 'Наряды бригад', 'work_order_lines' => 'Строки наряда',
        'brigades' => 'Бригады', 'bonus_payouts' => 'Выплаты бонусов',
        'employee_debts' => 'Долги сотрудников', 'employee_debt_payments' => 'Погашение долгов',
        'deal_items' => 'Товары сделки', 'pre_deal_items' => 'Товары заявки',
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
        'company_name' => 'Заказчик', 'client_name' => 'Товар', 'lot_number' => 'Количество',
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
        // Производство и бонусы: то, что вводят в модальных окнах.
        'brigade_id' => 'Бригада', 'foreman_id' => 'Бригадир', 'user_id' => 'Сотрудник',
        'employee_id' => 'Сотрудник', 'created_by' => 'Внёс', 'confirmed_by' => 'Подтвердил',
        'paid_by' => 'Выдал', 'product' => 'Изделие', 'qty_pcs' => 'Штук', 'qty_m2' => 'Метров²',
        'rate_pcs' => 'Ставка за штуку', 'rate_m2' => 'Ставка за м²', 'role' => 'Роль в наряде',
        'month' => 'За месяц', 'monthly_payment' => 'Платёж в месяц', 'employee_payout' => 'Вид выплаты',
        'sale_amount' => 'Цена продажи', 'markup_pct' => 'Наценка, %', 'bonus_percent' => 'Личный % бонуса',
        'partner_pct' => 'Доля партнёра, %', 'deal_type' => 'Тип сделки', 'project_id' => 'Заказ цеха',
        'deal_id' => 'Сделка', 'invoice_id' => 'Счёт', 'expenseable_id' => 'Запись-хозяин',
        'expenseable_type' => 'Тип хозяина', 'invoiceable_id' => 'Запись-хозяин', 'invoiceable_type' => 'Тип хозяина',
        'payment_date' => 'Дата оплаты', 'method' => 'Способ', 'branch' => 'Филиал', 'area_m2' => 'Площадь, м²',
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
        'method' => ['cash' => 'Наличные', 'bank' => 'Банк'],
        'role' => ['worker' => 'Рабочий', 'foreman' => 'Бригадир'],
        'deal_type' => ['production' => 'Своё производство', 'resale' => 'Перепродажа'],
        'employee_payout' => ['bonus' => 'Бонус', 'debt' => 'Выдача долга', 'advance' => 'Аванс', 'salary' => 'Зарплата'],
        'expenseable_type' => ['deal' => 'Сделка', 'project' => 'Заказ цеха'],
        'invoiceable_type' => ['deal' => 'Сделка', 'project' => 'Заказ цеха'],
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
    private const MONEY_FIELDS = ['amount', 'budget', 'salary', 'balance', 'receivable', 'price',
        'monthly_payment', 'sale_amount', 'rate_pcs', 'rate_m2', 'unit_price'];

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
        // Тот же словарь разбирает и снимок записи: «Сотрудник: 8» никому
        // ничего не говорит.
        $people = User::withTrashed()->pluck('name', 'id');
        $maps = [
            'deal_stage_id' => DealStage::pluck('name', 'id'),
            'project_stage_id' => ProjectStage::pluck('name', 'id'),
            'responsible_user_id' => $people,
            'assignee_id' => $people,
            'head_user_id' => $people,
            'user_id' => $people,
            'employee_id' => $people,
            'created_by' => $people,
            'confirmed_by' => $people,
            'paid_by' => $people,
            'foreman_id' => $people,
            'department_id' => Department::pluck('name', 'id'),
            'client_id' => Client::pluck('name', 'id'),
            'category_id' => ExpenseCategory::pluck('name', 'id'),
            'material_id' => Material::pluck('name', 'id'),
            'company_id' => Company::pluck('name', 'id'),
            'brigade_id' => Brigade::pluck('name', 'id'),
        ];
        $logs->setCollection(AuditFormatter::humanize($logs->getCollection(), $maps));

        // Связанная СДЕЛКА каждой строки: расход/счёт/платёж/заказ цеха/заявка/задача →
        // ссылка «QT-088», чтобы видно было, по какой сделке действие (батчем, без N+1).
        $col = $logs->getCollection();
        $ids = fn (string $t) => $col->where('table_name', $t)->pluck('record_id')->filter()->unique()->values();
        $dealByRecord = [
            'deals' => $ids('deals')->mapWithKeys(fn ($id) => [$id => $id]),
            'expenses' => Expense::whereIn('id', $ids('expenses'))->where('expenseable_type', 'deal')->pluck('expenseable_id', 'id'),
            'invoices' => Invoice::whereIn('id', $ids('invoices'))->where('invoiceable_type', 'deal')->pluck('invoiceable_id', 'id'),
            'payments' => Payment::whereIn('payments.id', $ids('payments'))
                ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')->where('invoices.invoiceable_type', 'deal')
                ->pluck('invoices.invoiceable_id', 'payments.id'),
            'projects' => Project::whereIn('id', $ids('projects'))->whereNotNull('deal_id')->pluck('deal_id', 'id'),
            'pre_deals' => PreDeal::whereIn('id', $ids('pre_deals'))->whereNotNull('deal_id')->pluck('deal_id', 'id'),
            'tasks' => Task::whereIn('id', $ids('tasks'))->where('taskable_type', 'deal')->pluck('taskable_id', 'id'),
        ];
        // withTrashed: у удалённой сделки номер показываем, но серым (без ссылки).
        $dealInfo = Deal::withTrashed()
            ->whereIn('id', collect($dealByRecord)->flatMap(fn ($m) => $m->values())->unique()->values())
            ->get(['id', 'number', 'deleted_at'])->keyBy('id');

        // Всё остальное — по-русски: таблица, поле, значения, даты, деньги.
        $logs->setCollection($logs->getCollection()->map(function ($log) use ($dealByRecord, $dealInfo, $maps) {
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
                'field' => $log->field_name && $log->field_name !== AuditLog::SNAPSHOT
                    ? (self::FIELD_LABELS[$log->field_name] ?? $log->field_name)
                    : null,
                'old' => $log->field_name === AuditLog::SNAPSHOT
                    ? null : $this->formatValue($log->field_name, $log->old_value),
                'new' => $log->field_name === AuditLog::SNAPSHOT
                    ? null : $this->formatValue($log->field_name, $log->new_value),
                // Снимок записи: что именно ввели в модальном окне (или что
                // унесли с собой при удалении).
                'snapshot' => $log->field_name === AuditLog::SNAPSHOT
                    ? $this->snapshotFields($log->new_value ?? $log->old_value, $maps)
                    : [],
            ];
        }));

        return Inertia::render('Audit/Index', [
            'logs' => $logs,
            'filters' => $request->only('table', 'action', 'user', 'from', 'to'),
            'tables' => AuditLog::query()->distinct()->orderBy('table_name')->pluck('table_name')
                ->map(fn ($t) => ['value' => $t, 'label' => self::TABLE_LABELS[$t] ?? $t])->values(),
            'users' => User::whereIn('id', AuditLog::distinct()->pluck('user_id')->filter())
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Снимок записи → список «поле: значение» по-русски.
     *
     * @param  array<string, Collection|array>  $maps
     * @return array<int, array{label: string, value: string}>
     */
    private function snapshotFields(?string $json, array $maps): array
    {
        $data = json_decode((string) $json, true);
        if (! is_array($data)) {
            return [];
        }

        $rows = [];
        foreach ($data as $field => $value) {
            // id самой записи и технические ключи читателю не нужны.
            if ($field === 'id' || $value === null || $value === '') {
                continue;
            }
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            $value = isset($maps[$field]) ? ($maps[$field][$value] ?? $value) : $value;

            $rows[] = [
                'label' => self::FIELD_LABELS[$field] ?? $field,
                'value' => (string) ($this->formatValue($field, (string) $value) ?? '—'),
            ];
        }

        return $rows;
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
            $moment = Carbon::parse($v);

            // Дата без времени хранится как 00:00:00 — «21.08.2026 00:00»
            // читается как «ночью», хотя времени там просто нет.
            return $moment->format('H:i:s') === '00:00:00'
                ? $moment->format('d.m.Y')
                : $moment->format('d.m.Y H:i');
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
