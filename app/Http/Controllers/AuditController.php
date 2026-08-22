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
use App\Support\AuditDictionary;
use App\Support\AuditFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends Controller
{
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
                'table' => AuditDictionary::table($log->table_name),
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
                    ? AuditDictionary::field($log->field_name)
                    : null,
                'old' => $log->field_name === AuditLog::SNAPSHOT
                    ? null : AuditDictionary::value($log->field_name, $log->old_value),
                'new' => $log->field_name === AuditLog::SNAPSHOT
                    ? null : AuditDictionary::value($log->field_name, $log->new_value),
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
                ->map(fn ($t) => ['value' => $t, 'label' => AuditDictionary::table($t)])->values(),
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
                'label' => AuditDictionary::field($field),
                'value' => (string) (AuditDictionary::value($field, (string) $value) ?? '—'),
            ];
        }

        return $rows;
    }
}
