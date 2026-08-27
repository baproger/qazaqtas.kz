<?php

namespace App\Http\Controllers;

use App\Models\Brigade;
use App\Models\DealItem;
use App\Models\Project;
use App\Models\WorkOrder;
use App\Services\ProductionBonusService;
use App\Services\ProductionProgressService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Работа по позиции заказа прямо из карточки цеха.
 *
 * Бригадир отливает и тут же записывает: «сделал 20 штук». Раньше за этим
 * надо было идти на страницу «Производство» и заводить наряд руками — и
 * поэтому не записывали вовсе, а объём вспоминали в конце месяца.
 *
 * Запись — это ОБЫЧНЫЙ НАРЯД, а не вторая сущность: он попадает в «Наряды по
 * сменам», ждёт мастера и после подтверждения идёт в бонус и в «Кто сколько
 * сделал за месяц». Цепочка одна, счётчик один.
 */
class ProjectItemController extends Controller
{
    /**
     * Позиция должна принадлежать сделке этого заказа.
     *
     * Иначе по ссылке на свой заказ можно было бы списать объём на чужую
     * сделку — вместе с бонусом.
     */
    private function assertItemOfProject(Project $project, DealItem $item): void
    {
        abort_unless($project->deal_id !== null && (int) $item->deal_id === (int) $project->deal_id, 404);
    }

    /**
     * Бригада, от имени которой пишется выработка.
     *
     * Бригадир пишет от своей: чужую выработку он себе не припишет. У
     * руководства своей бригады нет — оно выбирает её в запросе.
     */
    private function resolveBrigade(Request $request, Project $project): Brigade
    {
        $user = $request->user();

        $query = Brigade::query()->where('is_active', true)
            ->when(! $user->hasAnyRole(['admin', 'director']), fn ($q) => $q->where('foreman_id', $user->id))
            ->when($request->filled('brigade_id'), fn ($q) => $q->where('id', $request->integer('brigade_id')));

        // Своего цеха: бригада Шымкента не отчитывается за заказ Алматы.
        $brigade = (clone $query)->where('workshop', $project->workshop)->first()
            ?? $query->first();

        if (! $brigade) {
            throw ValidationException::withMessages([
                'qty' => 'Нет бригады, от имени которой записать работу. Бригаду назначает руководство.',
            ]);
        }

        return $brigade;
    }

    /**
     * «Сделал N» по позиции — сменный наряд со статусом «ждёт мастера».
     *
     * Без подтверждения выработка бонуса не даёт: иначе объём можно было бы
     * приписать себе. В цехе цифра сразу видна как «ждёт», а в «сделано»
     * переходит после галочки мастера.
     */
    public function output(Request $request, Project $project, DealItem $item,
        ProductionBonusService $bonuses, ProductionProgressService $progress): RedirectResponse
    {
        $this->authorize('view', $project);
        abort_unless($request->user()->worksInWorkshop($project->workshop), 403,
            'Заказ другого цеха: у вас доступ только к своему цеху.');
        $this->assertItemOfProject($project, $item);

        $data = $request->validate([
            'qty' => ['required', 'numeric', 'min:0.01'],
            'brigade_id' => ['nullable', 'exists:brigades,id'],
            'date' => ['nullable', 'date'],
        ], ['qty.required' => 'Укажите, сколько сделано.']);

        $brigade = $this->resolveBrigade($request, $project);
        $date = $data['date'] ?? now()->toDateString();
        $qty = round((float) $data['qty'], 2);

        // В чём считается позиция — в метрах или в штуках, — решает её
        // единица. Одно правило на всю цепочку: ProductionProgressService.
        $measure = $progress->measure($item->unit);

        $order = WorkOrder::create([
            'company_id' => $brigade->company_id ?: $item->deal?->company_id,
            'brigade_id' => $brigade->id,
            'project_id' => $project->id,
            'deal_item_id' => $item->id,
            'date' => $date,
            'product' => $item->name,
            'status' => 'draft',
            'created_by' => $request->user()->id,
        ]);

        // Объём делим поровну между рабочими смены: кто сколько именно —
        // правится в наряде на «Производстве», а здесь бригадир записывает
        // работу бригады, стоя у формы. Некому делить — пишем на бригадира.
        $members = $brigade->members()->pluck('users.id');
        $targets = $members->isNotEmpty() ? $members : collect([$brigade->foreman_id])->filter();
        $share = $targets->isNotEmpty() ? round($qty / $targets->count(), 2) : 0;

        $rows = $targets->values()->map(fn ($id, $i) => [
            'user_id' => $id,
            // Остаток от деления кладём в первую строку: сумма строк обязана
            // сойтись с введённым объёмом до копейки.
            'qty_'.$measure => $i === 0 ? round($qty - $share * ($targets->count() - 1), 2) : $share,
        ])->all();

        $bonuses->syncLines($order->load('brigade'), $rows);

        return back()->with('success', 'Записано: '.$qty.' '.($item->unit ?: '').' — наряд ждёт подтверждения мастера.');
    }

    /**
     * «Товар закончен» — и обратно.
     *
     * Отдельно от счётчика: бывает 22 из 24 и «больше не будет». Пока не
     * закрыты все позиции, заказ не уходит на «Логистику».
     */
    public function finish(Request $request, Project $project, DealItem $item): RedirectResponse
    {
        $this->authorize('view', $project);
        abort_unless($request->user()->worksInWorkshop($project->workshop), 403,
            'Заказ другого цеха: у вас доступ только к своему цеху.');
        $this->assertItemOfProject($project, $item);

        $finished = ! $item->isFinished();
        $item->update([
            'finished_at' => $finished ? now() : null,
            'finished_by' => $finished ? $request->user()->id : null,
        ]);

        return back()->with('success', $finished
            ? 'Товар «'.$item->name.'» отмечен как законченный.'
            : 'Отметка снята — товар снова в работе.');
    }
}
