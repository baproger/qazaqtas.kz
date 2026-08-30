<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\PayrollService;
use App\Support\CurrentCompany;
use App\Support\ReportCache;
use App\Support\StickyFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Реестр сделок (договоров) — сводная таблица «как в Excel» для руководства:
 * каждая строка = сделка со всей денежной математикой (та же формула, что на
 * карточке сделки и в ЗП: налог → остаток → маржа → бонус → фирма) + Итого.
 * Только admin/director — здесь видны бонусы всех менеджеров.
 */
class ReportController extends Controller
{
    /** Сколько строк отчёта отдаём на страницу. */
    private const PER_PAGE = 100;

    /** Размер пачки при подсчёте итогов по всей выборке. */
    private const TOTALS_CHUNK = 500;

    public function deals(Request $request): Response
    {
        // Фильтр переживает уход со страницы: пришли без параметров —
        // подставляем сохранённый набор (App\Support\StickyFilters).
        StickyFilters::apply($request, 'reports', ['search', 'from', 'to', 'manager', 'stage']);

        abort_unless($request->user()->hasAnyRole(['admin', 'director']) && $request->user()->can('report.viewAny'), 403);

        // Тяжёлый отчёт — из кеша (5 мин, сброс при изменении денег): см. ReportCache.
        return Inertia::render('Reports/Deals', ReportCache::remember($request, 'deals', fn () => $this->build($request)));
    }

    /** @return array<string, mixed> */
    private function build(Request $request): array
    {

        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;

        $search = $request->string('search')->toString();
        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();
        $managerId = $request->integer('manager') ?: null;
        $stageId = $request->integer('stage') ?: null;

        $dealQuery = Deal::forCurrentCompany()
            ->where('status', '!=', 'cancelled')
            ->with(['responsible:id,name', 'stage:id,name,color,is_won,stage_type,is_closing,ignores_deadline'])
            ->when($search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('number', 'like', "%{$s}%")->orWhere('company_name', 'like', "%{$s}%")
                ->orWhere('client_name', 'like', "%{$s}%")->orWhere('bin', 'like', "%{$s}%")
                ->orWhere('address', 'like', "%{$s}%")))
            ->when($managerId, fn ($q, $m) => $q->where('responsible_user_id', $m))
            ->when($stageId, fn ($q, $s) => $q->where('deal_stage_id', $s))
            // Период — по дате ДОГОВОРА (без неё — по дате создания): та же
            // логика, что у фильтра «Месяц» на Финансах, цифры совпадают.
            ->when($from || $to, fn ($q) => $q->where(fn ($w) => $w
                ->where(fn ($c) => $c->whereNotNull('contract_date')
                    ->when($from, fn ($q2, $d) => $q2->whereDate('contract_date', '>=', $d))
                    ->when($to, fn ($q2, $d) => $q2->whereDate('contract_date', '<=', $d)))
                ->orWhere(fn ($c) => $c->whereNull('contract_date')
                    ->when($from, fn ($q2, $d) => $q2->whereDate('created_at', '>=', $d))
                    ->when($to, fn ($q2, $d) => $q2->whereDate('created_at', '<=', $d)))))
            ->latest();

        // Страница таблицы. Раньше в браузер уезжали ВСЕ сделки выборки: на
        // тысяче договоров это мегабайты JSON и повисший отчёт. Итоги при
        // этом считаются по всей выборке, а не по видимой странице —
        // руководителю нужен итог периода, а не итог экрана.
        $page = (clone $dealQuery)->paginate(self::PER_PAGE)->withQueryString();
        $deals = $page->getCollection();

        $rows = $this->buildRows($deals, $taxRate);

        // Итоги — по ВСЕЙ выборке, а не по видимой странице: руководителю
        // нужен итог периода. Считаем пачками, чтобы память не зависела от
        // числа сделок.
        $totals = $this->totalsFor(clone $dealQuery, $taxRate);

        // Опции фильтров: активные менеджеры и этапы воронки текущей компании
        // (в режиме «Все компании» — обе воронки с пометкой фирмы).
        $companyId = CurrentCompany::id() ?: null;
        $companyNames = Company::pluck('name', 'id');
        $stageOptions = DealStage::with('translations')->where('is_active', true)
            ->when($companyId, fn ($q, $c) => $q->where(fn ($w) => $w->where('company_id', $c)->orWhereNull('company_id')))
            ->orderBy('order')->get()
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->translatedName().(! $companyId && $s->company_id ? ' · '.($companyNames[$s->company_id] ?? '') : '')])
            ->values();

        return [
            'rows' => $rows,
            // Ссылки страниц: сама таблица показывает сотню строк, итоги под
            // ней — по всему периоду.
            'links' => $page->linkCollection(),
            'shown' => $page->count(),
            'totals' => $totals,
            'taxRate' => $taxRate * 100,
            'filters' => ['search' => $search, 'from' => $from, 'to' => $to, 'manager' => $managerId, 'stage' => $stageId],
            // Для фильтра: менеджеры отдельно, остальные — по отделам (сворачиваются).
            'managers' => User::where('is_active', true)
                ->with(['roles:id,name', 'department:id,name'])
                ->orderBy('name')->get(['id', 'name', 'department_id'])
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'is_manager' => $u->roles->contains('name', 'manager'),
                    'department' => $u->department?->name,
                ])->values(),
            'stageOptions' => $stageOptions,
        ];
    }

    /**
     * Строки отчёта по набору сделок.
     *
     * Одна формула на страницу таблицы и на итоги: итоги считаются по всей
     * выборке пачками, и второй расчёт «покороче» рано или поздно разошёлся
     * бы со строками — спорить пришлось бы о том, какая цифра врёт.
     *
     * @param  Collection<int, Deal>  $deals
     * @return Collection<int, array<string, mixed>>
     */
    private function buildRows($deals, float $taxRate)
    {
        if ($deals->isEmpty()) {
            return collect();
        }

        // Оплачено по сделке — платежи по её счетам (одним запросом на всех).
        $paidByDeal = Payment::join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->where('invoices.invoiceable_type', 'deal')
            ->whereIn('invoices.invoiceable_id', $deals->pluck('id'))
            ->groupBy('invoices.invoiceable_id')
            ->selectRaw('invoices.invoiceable_id as deal_id, sum(payments.amount) as paid')
            ->pluck('paid', 'deal_id');

        // Подтверждённые расходы РАЗДЕЛЬНО: закуп со склада (material_id),
        // доставка, закуп (тип из формы расхода) и прочие — свои колонки.
        $expByDeal = Expense::where('status', 'confirmed')->where('expenseable_type', 'deal')
            ->whereIn('expenseable_id', $deals->pluck('id'))
            ->groupBy('expenseable_id')
            ->selectRaw("expenseable_id as deal_id,
                sum(case when material_id is not null then amount else 0 end) as material,
                sum(case when material_id is null and type = 'delivery' then amount else 0 end) as delivery,
                sum(case when material_id is null and type = 'purchase' then amount else 0 end) as purchase,
                sum(case when material_id is null and type = 'assembly' then amount else 0 end) as assembly,
                sum(case when material_id is null and (type is null or type not in ('delivery','purchase','assembly')) then amount else 0 end) as other")
            ->get()->keyBy('deal_id');

        // Активный заказ цеха по сделке: этап цеха показывается прямо в общей
        // таблице (вторым бейджем в колонке «Этап») — цех и сделки вместе.
        $workshopByDeal = Project::query()
            ->with('stage:id,name,color')
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->whereIn('deal_id', $deals->pluck('id'))
            ->get()->keyBy('deal_id');

        return $deals->map(function ($d) use ($paidByDeal, $expByDeal, $workshopByDeal, $taxRate) {
            $budget = (float) $d->budget;
            $material = (float) ($expByDeal[$d->id]->material ?? 0);
            $delivery = (float) ($expByDeal[$d->id]->delivery ?? 0);
            $purchase = (float) ($expByDeal[$d->id]->purchase ?? 0);
            $assembly = (float) ($expByDeal[$d->id]->assembly ?? 0);
            $other = (float) ($expByDeal[$d->id]->other ?? 0);
            $expense = $material + $delivery + $purchase + $assembly + $other;
            $tax = round($budget * $taxRate, 2);
            $partner = PayrollService::partnerSum($budget, $d->partner_pct);
            $remainder = round($budget - $tax - $expense - $partner, 2);
            // Та же формула бонуса, что на карточке сделки и в ЗП (с ручным % финансиста).
            $bonus = PayrollService::dealBonus($remainder,
                $d->bonus_rate_override !== null ? (float) $d->bonus_rate_override : null,
                PayrollService::userBonusPercent($d->responsible_user_id),
                $d->deal_type ?? PayrollService::TYPE_PRODUCTION)['total'];
            $company = round($remainder - $bonus, 2);

            return [
                'id' => $d->id,
                'number' => $d->number,
                'bin' => $d->bin,
                'company_name' => $d->company_name,
                'address' => $d->address,
                'product' => $d->client_name, // «Наименование товара» (историческое имя колонки)
                'qty' => trim(($d->lot_number ?? '').' '.($d->unit ?? '')),
                'budget' => $budget,
                'paid' => (float) ($paidByDeal[$d->id] ?? 0),
                'material' => $material,
                'delivery' => $delivery,
                'purchase' => $purchase,
                'assembly' => $assembly,
                'other' => $other,
                'partner' => $partner,
                'partner_pct' => $d->partner_pct !== null ? (float) $d->partner_pct : null,
                'tax' => $tax,
                'remainder' => $remainder,
                'margin' => PayrollService::marginPct($budget, $remainder, $tax),
                'bonus' => $bonus,
                'company' => $company,
                'manager' => $d->responsible?->name,
                'deadline' => optional($d->deadline)->toDateString(),
                'stage' => $d->stage?->name,
                'stage_color' => $d->stage?->color,
                'workshop_stage' => $workshopByDeal->get($d->id)?->stage?->name,
                'workshop_color' => $workshopByDeal->get($d->id)?->stage?->color,
                'workshop_number' => $workshopByDeal->get($d->id)?->number,
                'is_won' => (bool) $d->stage?->is_won,
                // Группы подсветки по stage_type (имя этапа ненадёжно):
                // Акт/ЭСФ — зелёные как won; Логистика/Сборка — жёлтые.
                'is_pending_won' => (bool) $d->stage?->is_closing,
                'is_esf' => (bool) $d->stage?->ignores_deadline,
                'is_logistics' => in_array($d->stage?->stage_type, ['logistics', 'assembly'], true),
            ];
        })->values();
    }

    /**
     * Итоги по всей выборке — пачками по TOTALS_CHUNK сделок.
     *
     * @return array<string, float|int>
     */
    private function totalsFor($query, float $taxRate): array
    {
        $keys = ['budget', 'paid', 'material', 'delivery', 'purchase', 'assembly',
            'other', 'partner', 'tax', 'remainder', 'bonus', 'company'];
        $sums = array_fill_keys($keys, 0.0);
        $count = 0;

        // reorder(): chunkById требует сортировки по id, а запрос отсортирован
        // по дате создания для таблицы.
        $query->reorder()->chunkById(self::TOTALS_CHUNK, function ($chunk) use (&$sums, &$count, $taxRate, $keys) {
            foreach ($this->buildRows($chunk, $taxRate) as $row) {
                foreach ($keys as $key) {
                    $sums[$key] += (float) $row[$key];
                }
                $count++;
            }
        });

        $sums = array_map(fn ($v) => round($v, 2), $sums);
        // Итоговая маржа — ТОЙ ЖЕ формулой, что в строках таблицы
        // (PayrollService::marginPct), а не «чистая прибыль / бюджет».
        // Раньше это были две разные величины под одной подписью: строка
        // считала маржу до налога и бонуса, итог — после обоих, и колонка
        // не сходилась сама с собой.
        $sums['margin'] = PayrollService::marginPct($sums['budget'], $sums['remainder'], $sums['tax']);
        $sums['count'] = $count;

        return $sums;
    }
}
