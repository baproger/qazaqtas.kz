<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PayrollAdjustment;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkHour;
use App\Services\BonusPayoutService;
use App\Services\EmployeeDebtService;
use App\Services\PayrollService;
use App\Services\ProductionBonusService;
use App\Support\CurrentCompany;
use App\Support\FinanceAudit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PayrollController extends Controller
{
    /** Корректировки и оклад вводит только бухгалтер (financist) или админ. */
    private function canManage(Request $request): bool
    {
        return $request->user()->hasAnyRole(['admin', 'financist']);
    }

    public function index(Request $request, PayrollService $payroll): Response
    {
        $user = $request->user();
        abort_unless($user->can('payroll.view'), 403);

        $leadership = $user->hasAnyRole(['admin', 'director', 'financist']);
        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;

        // Месяц корректировок (отгулы/больничные/штрафы/премии): YYYY-MM.
        $month = preg_match('/^\d{4}-\d{2}$/', $request->string('month')->toString())
            ? $request->string('month')->toString() : now()->format('Y-m');
        $monthStart = $month.'-01';
        $monthEnd = Carbon::parse($monthStart)->endOfMonth()->toDateString();

        $adjustments = PayrollAdjustment::with('creator:id,name')
            ->whereDate('date', '>=', $monthStart)->whereDate('date', '<=', $monthEnd)
            ->orderBy('date')->get()->groupBy('user_id');

        // Почасовой расчёт (Excel владельца): ставка/час = оклад ÷ норма часов месяца,
        // начислено = часы × ставка. Норма — одна на месяц для всех (Setting),
        // fallback — последняя использованная норма (work_norm_default).
        $normHours = (float) Setting::get('work_norm_'.$month, Setting::get('work_norm_default', 176));
        $hoursByUser = WorkHour::where('month', $month)->pluck('hours', 'user_id');

        // Single source of truth for the payroll math (shared with Analytics & Finance).
        $rows = $payroll->perUser(true)->sortByDesc('bonus')->values();
        if (! $leadership) {
            $rows = $rows->filter(fn ($r) => $r['uid'] === $user->id)->values();
        }

        // Per-deal breakdown so a row can expand into the employee's «Оплата успешно»
        // and «Акт утверждение» deals — the raw data the financist needs to check ЗП.
        $breakdown = $payroll->dealBreakdown();
        // Отдел сотрудника — ведомость показывается раздельными секциями по отделам.
        $deptByUser = User::whereIn('id', $rows->pluck('uid'))
            ->with('department:id,name')->get(['id', 'department_id'])->keyBy('id');
        // Своя норма часов у отдела (цех 200 ч, менеджеры 220 ч…): work_norm_{месяц}:dept:{id};
        // нет своей — действует общая норма месяца.
        $deptNorms = $deptByUser->pluck('department_id')->filter()->unique()
            ->mapWithKeys(fn ($id) => [$id => Setting::get('work_norm_'.$month.':dept:'.$id)])
            ->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);
        // Бонус за ВЫБРАННЫЙ месяц — одним запросом на всю ведомость (тем же
        // методом, что считает удержание долгов; второго расчёта нет).
        // Бонус месяца — тоже целиком: сделки и выработка вместе.
        $bonusMonth = app(BonusPayoutService::class)
            ->accrualsByMonths($rows->pluck('uid'), [$month])[$month] ?? [];

        // Долги: считаем план удержания только тем, у кого долг открыт —
        // бонус за месяц запрашивается по одному сотруднику, и звать его на
        // всю ведомость было бы дорого.
        $debtPlans = collect(app(EmployeeDebtService::class)
            ->planForUsers($rows->pluck('uid'), $month));

        // Уже выплаченный бонус: в «К выплате» он входить не должен, иначе
        // бухгалтер заплатит его второй раз. Копится ровно то, что не забрали.
        $bonusPaid = app(BonusPayoutService::class)->paidTotals($rows->pluck('uid'));

        // Бонус за выработку цеха. Ведомость обязана показывать ВЕСЬ бонус
        // человека: бригадир зарабатывает объёмом, а не процентом со сделок,
        // и без этого его строка выглядела бы как «только оклад», расходясь
        // со страницей «Бонусы».
        $bonusProduction = app(ProductionBonusService::class)->totalsByUser($rows->pluck('uid'));

        $rows = $rows->map(function ($r) use ($breakdown, $adjustments, $hoursByUser, $normHours, $deptByUser, $deptNorms, $debtPlans, $bonusMonth, $bonusPaid, $bonusProduction) {
            $r['dealsList'] = array_values(($breakdown->get($r['uid']) ?? collect())->all());
            $adj = $adjustments->get($r['uid']) ?? collect();
            $deductions = round((float) $adj->whereIn('type', PayrollAdjustment::DEDUCTIONS)->sum('amount'), 2);
            $additions = round((float) $adj->where('type', 'bonus')->sum('amount'), 2);
            $r['adjustments'] = $adj->map(fn ($a) => [
                'id' => $a->id, 'type' => $a->type, 'days' => $a->days !== null ? (float) $a->days : null,
                'amount' => (float) $a->amount, 'date' => optional($a->date)->toDateString(),
                'created_at' => optional($a->created_at)->toIso8601String(),
                'note' => $a->note, 'creator' => $a->creator?->name,
            ])->values();
            $r['deductions'] = $deductions;
            $r['additions'] = $additions;

            // Почасовая база: часы введены → часы × (оклад ÷ норма отдела или общая); нет — полный оклад.
            $deptId = $deptByUser[$r['uid']]?->department_id;
            $norm = (float) ($deptNorms[$deptId] ?? $normHours);
            $hours = isset($hoursByUser[$r['uid']]) ? (float) $hoursByUser[$r['uid']] : null;
            $rate = $norm > 0 ? $r['salary'] / $norm : 0.0;
            $r['hours'] = $hours;
            $r['hourly_rate'] = $norm > 0 ? round($rate, 2) : null;
            $r['base'] = $hours !== null && $norm > 0 ? round($hours * $rate, 2) : $r['salary'];
            // Бонус выбранного месяца — справочная цифра рядом с бонусом «за всё
            // время». В «К выплате» он НЕ входит: решение владельца BAIA,
            // перенесено как есть — бонус выплачивается по факту закрытия
            // сделок, а не помесячно.
            $r['bonus_month'] = (float) ($bonusMonth[$r['uid']] ?? 0);
            // Бонус выплачивают отдельно и не обязательно каждый месяц: в
            // «К выплате» идёт только НЕВЫПЛАЧЕННЫЙ остаток.
            $r['bonus_paid'] = (float) ($bonusPaid[$r['uid']] ?? 0);
            // Бонус строки = сделки + выработка цеха: у человека он один.
            $r['bonus_production'] = (float) ($bonusProduction[$r['uid']] ?? 0);
            $r['bonus_deals'] = $r['bonus'];
            $r['bonus'] = round($r['bonus'] + $r['bonus_production'], 2);
            $r['bonus_left'] = round(max($r['bonus'] - $r['bonus_paid'], 0), 2);
            // К выплате = почасовая база (или оклад) + остаток бонуса − удержания + премии.
            $r['payout'] = round($r['base'] + $r['bonus_left'], 2);
            // Долг — ОТДЕЛЬНОЕ поле расчёта, а не корректировка: аванс и долг
            // независимы и не гасят друг друга. Удерживается только план
            // текущего месяца и только из бонуса (EmployeeDebtService).
            $r['debt'] = $debtPlans[$r['uid']] ?? null;
            $r['debt_charge'] = (float) ($debtPlans[$r['uid']]['charge'] ?? 0);
            $r['final'] = round($r['payout'] - $deductions + $additions - $r['debt_charge'], 2);
            $r['department'] = $deptByUser[$r['uid']]?->department?->name;
            $r['department_id'] = $deptId;

            return $r;
        });

        return Inertia::render('Payroll/Index', [
            'rows' => $rows,
            'leadership' => $leadership,
            'canManage' => $this->canManage($request),
            'month' => $month,
            'normHours' => $normHours,
            'deptNorms' => $deptNorms,
            'taxRate' => $taxRate * 100,
            // Ставки бонуса — из настроек: шкала в правой колонке показывает
            // то, по чему реально платят.
            'bonusRates' => [
                'sale' => PayrollService::rateForType(PayrollService::TYPE_PRODUCTION),
                'resale' => PayrollService::rateForType(PayrollService::TYPE_RESALE),
            ],
            'totals' => [
                'budget' => (float) $rows->sum('budget'),
                'tax' => (float) $rows->sum('tax'),
                'expense' => (float) $rows->sum('expense'),
                'bonus' => (float) $rows->sum('bonus'),
                'salary' => (float) $rows->sum('salary'),
                'base' => (float) $rows->sum('base'),
                'payout' => (float) $rows->sum('payout'),
                'deductions' => (float) $rows->sum('deductions'),
                'additions' => (float) $rows->sum('additions'),
                'bonus_month' => (float) $rows->sum('bonus_month'),
                'bonus_paid' => (float) $rows->sum('bonus_paid'),
                'bonus_left' => (float) $rows->sum('bonus_left'),
                'debt_charge' => (float) $rows->sum('debt_charge'),
                'final' => (float) $rows->sum('final'),
                'company' => (float) $rows->sum('company'),
            ],
        ]);
    }

    /**
     * Корректировка ЗП: отгул/больничный — можно днями (сумма = оклад/22 × дни),
     * штраф/премия — суммой. Только бухгалтер/админ.
     */
    public function storeAdjustment(Request $request): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Корректировки вводит бухгалтер или админ.');

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'type' => ['required', Rule::in(PayrollAdjustment::TYPES)],
            'days' => ['nullable', 'numeric', 'min:0.5', 'max:31'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
            // Для аванса: откуда выданы деньги (нал/банк) — уйдёт в Расходы.
            'payment_method' => ['nullable', Rule::in(['cash', 'bank'])],
        ]);

        $this->assertSameCompany($request, User::findOrFail($data['user_id']));

        // Автосумма для отгула/больничного: оклад / 22 рабочих дня × дни.
        if (empty($data['amount']) && ! empty($data['days']) && in_array($data['type'], ['absence', 'sick'], true)) {
            $salary = (float) (User::find($data['user_id'])->salary ?? 0);
            $data['amount'] = round($salary / 22 * (float) $data['days'], 2);
        }
        if (empty($data['amount']) || (float) $data['amount'] <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Укажите сумму (или дни — для отгула/больничного при заполненном окладе).',
            ]);
        }

        $data['created_by'] = $request->user()->id;

        // АВАНС — реальные деньги из кассы/банка: фиксируем и в Финансах —
        // подтверждённый расход компании, категория «Расходы по сотрудникам»
        // (не «прочие»). Удаление корректировки удалит и расход.
        if ($data['type'] === 'advance') {
            $employee = User::find($data['user_id']);
            // Категория ищется по служебному коду, а не по имени: имя
            // владелец правит из админки, код неизменен.
            $category = ExpenseCategory::firstOrCreate(
                ['code' => ExpenseCategory::EMPLOYEE],
                ['name' => 'Расходы по сотрудникам', 'is_active' => true]
            );
            $expense = Expense::create([
                'company_id' => CurrentCompany::id()
                    ?: $employee->companies()->value('companies.id'),
                'category_id' => $category->id,
                'type' => 'direct',
                'amount' => $data['amount'],
                'date' => $data['date'],
                'description' => 'Аванс сотруднику: '.$employee->name
                    .(! empty($data['note']) ? ' — '.$data['note'] : ''),
                'responsible_user_id' => $employee->id,
                // Кому выдали — явной связью: описание устаревает при
                // переименовании сотрудника, а фильтровать по строке нельзя.
                'employee_id' => $employee->id,
                'employee_payout' => 'advance',
                'status' => 'confirmed',
                'payment_method' => $data['payment_method'] ?? 'cash',
                'confirmed_by' => $request->user()->id,
                'confirmed_at' => now(),
            ]);
            $data['expense_id'] = $expense->id;
            $data['payment_method'] = $data['payment_method'] ?? 'cash';
        }

        PayrollAdjustment::create($data);

        return back()->with('success', $data['type'] === 'advance'
            ? 'Аванс добавлен и зафиксирован в Расходах на Финансах.'
            : 'Корректировка добавлена.');
    }

    public function destroyAdjustment(Request $request, PayrollAdjustment $adjustment): RedirectResponse
    {
        abort_unless($this->canManage($request), 403);
        // Аванс: удаляем и его расход на Финансах (деньги вернулись в кассу).
        // Это движение денег, поэтому СЕО и директор узнают о нём, как и о
        // любом другом удалении финзаписи.
        if ($adjustment->expense_id) {
            Expense::find($adjustment->expense_id)?->delete();
            FinanceAudit::notifyDeleted(
                'Аванс сотруднику на '.number_format((float) $adjustment->amount, 0, '.', ' ').' ₸'
            );
        }
        $adjustment->delete();

        return back()->with('success', 'Корректировка удалена.');
    }

    /**
     * Изоляция фирм в ведомости: оклад, часы и корректировки ставятся только
     * своим сотрудникам. Админ работает со всеми (Gate::before), остальные —
     * с теми, с кем делят фирму.
     */
    private function assertSameCompany(Request $request, User $user): void
    {
        $companies = $user->companies()->pluck('companies.id');
        abort_unless(
            $companies->isEmpty() || $companies->contains(fn ($id) => $request->user()->worksInCompany((int) $id)),
            403,
            'Сотрудник другой фирмы.'
        );
    }

    /** Оклад вводит бухгалтер/админ прямо в ведомости. */
    public function updateSalary(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Оклад вводит бухгалтер или админ.');
        $this->assertSameCompany($request, $user);

        $data = $request->validate(['salary' => ['required', 'numeric', 'min:0', 'max:99999999']]);
        $user->update(['salary' => $data['salary']]);

        return back()->with('success', 'Оклад обновлён.');
    }

    /**
     * Отработанные часы сотрудника за месяц (почасовой оклад). Пустое значение —
     * удаляет запись, сотрудник возвращается на полный оклад.
     */
    public function updateHours(Request $request, User $user): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Часы вводит бухгалтер или админ.');
        $this->assertSameCompany($request, $user);

        $data = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:744'],
        ]);

        if ($data['hours'] === null) {
            WorkHour::where('user_id', $user->id)->where('month', $data['month'])->delete();

            return back()->with('success', 'Часы удалены — начисляется полный оклад.');
        }

        WorkHour::updateOrCreate(
            ['user_id' => $user->id, 'month' => $data['month']],
            ['hours' => $data['hours'], 'created_by' => $request->user()->id]
        );

        return back()->with('success', 'Отработанные часы сохранены.');
    }

    /**
     * Норма часов месяца (знаменатель ставки за час). Без department_id — общая
     * для всех; с department_id — своя норма отдела (цех 200 ч, менеджеры 220 ч…),
     * пустая norm сбрасывает отдел на общую норму.
     */
    public function updateNorm(Request $request): RedirectResponse
    {
        abort_unless($this->canManage($request), 403, 'Норму часов вводит бухгалтер или админ.');

        $data = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'norm' => ['nullable', 'required_without:department_id', 'numeric', 'min:1', 'max:744'],
            'department_id' => ['nullable', 'exists:departments,id'],
        ]);

        if (! empty($data['department_id'])) {
            $key = 'work_norm_'.$data['month'].':dept:'.$data['department_id'];
            if ($data['norm'] === null) {
                // first()?->delete() — событием модели сбрасывается кэш settings.all.
                Setting::where('key', $key)->first()?->delete();

                return back()->with('success', 'Норма отдела сброшена — действует общая норма месяца.');
            }
            Setting::set($key, $data['norm']);

            return back()->with('success', 'Норма часов отдела сохранена.');
        }

        Setting::set('work_norm_'.$data['month'], $data['norm']);
        // Запоминаем как значение по умолчанию — следующие месяцы предзаполнятся им.
        Setting::set('work_norm_default', $data['norm']);

        return back()->with('success', 'Норма часов на месяц сохранена.');
    }
}
