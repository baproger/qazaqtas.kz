<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\DealGuards;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\ProjectStage;
use App\Services\ProjectService;
use App\Services\StageTransitionService;
use App\Support\NotificationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Движение сделки по воронке: этап, «Далее», гейт-задача, отправка в цех.
 *
 * Отделено от DealController, который отвечает за саму сделку (создать,
 * показать, изменить, удалить). Переходы — отдельная история со своими
 * правилами: гейты этапов, системные типы из админки, создание заказа цеха.
 */
class DealStageController extends Controller
{
    use DealGuards;

    /**
     * Галочка-гейт текущего этапа: закрывает гейт-задачу («Выставить акт…»,
     * «Подтвердить дизайн…» и т.п.), после чего сделку можно двигать дальше.
     * Ставит её роль гейта этапа (технолог — «Замер и расчёт», снабженец —
     * «Закуп сырья», бухгалтер — АКТ/ЭСФ/Оплата) или админ.
     */
    public function completeStageTask(Request $request, Deal $deal): RedirectResponse
    {
        // Не 'update': технолог/снабженец не редактируют сделку, но гейт ставят.
        $this->authorize('view', $deal);

        $gateStage = self::gateStage($deal);
        abort_unless($gateStage !== null, 404);

        $gateRole = $gateStage->gate_task_role ?: 'financist';
        abort_unless(
            $request->user()->hasRole('admin') || $request->user()->hasRole($gateRole),
            403,
            'Галочку ставит только '.(self::GATE_ROLE_LABELS[$gateRole] ?? $gateRole).' или админ.'
        );

        // Галочка гейта закрывает задачу — и гасит её уведомления у исполнителей.
        $deal->tasks()->where('title', 'like', $gateStage->gate_task_title.'%')->where('status', '!=', 'done')
            ->get()->each(function ($t) {
                $t->update(['status' => 'done', 'completed_at' => now()]);
                NotificationResolver::taskDone($t);
            });

        return back()->with('success', 'Галочка поставлена — сделку можно переводить дальше.');
    }

    /**
     * Перенос на выбранный этап — право `moveStage`, а не `advance`: «Далее»
     * есть и у технолога, но таскать сделку по воронке он не должен, а
     * назначенный бригадир — должен. Запреты этапов (Акт, ЭСФ и «Оплата
     * успешно» — только бухгалтеру) остаются в StageTransitionService.
     */
    public function updateStage(Request $request, Deal $deal, StageTransitionService $transitions): RedirectResponse
    {
        $this->authorize('moveStage', $deal);

        $validated = $request->validate(['deal_stage_id' => ['required', 'exists:deal_stages,id']]);
        $target = DealStage::findOrFail($validated['deal_stage_id']);

        // Причину отказа (гейты этапов) показываем красным баннером, а не
        // тихой ошибкой валидации, которую на канбане не видно.
        try {
            $transitions->moveToStage($deal, $target);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return back()->with('success', 'Этап сделки обновлён.');
    }

    public function advance(Deal $deal, StageTransitionService $transitions): RedirectResponse
    {
        // Не 'update': у технолога есть право «Далее» со своего этапа (DealPolicy::advance).
        $this->authorize('advance', $deal);
        // Следующий этап — по ПОЗИЦИИ в воронке (не по order > current): при
        // задвоенном order переход не перескакивает соседний этап.
        $funnel = DealStage::funnel($deal->company_id ? (int) $deal->company_id : null)->values();
        $idx = $funnel->search(fn ($s) => $s->id === $deal->deal_stage_id);
        $next = $idx !== false ? $funnel->get($idx + 1) : $funnel->first();
        if ($next) {
            try {
                $transitions->moveToStage($deal, $next);
            } catch (ValidationException $e) {
                return back()->with('error', collect($e->errors())->flatten()->first());
            }

            return back()->with('success', 'Сделка переведена на этап «'.$next->name.'».');
        }

        return back()->with('error', 'Это последний этап.');
    }

    public function sendToWorkshop(Request $request, Deal $deal, ProjectService $projects): RedirectResponse
    {
        $this->authorize('update', $deal);
        if ($deal->project && $deal->project->status !== 'completed') {
            return back()->with('error', 'Заказ уже в цехе.');
        }

        // Где показывается кнопка «В цех», решает системный тип этапа
        // (Настройки → Этапы). Запрет на отправку с других этапов здесь
        // намеренно НЕ ставим: сделку в цех отправляют и вручную, минуя
        // канбан, — так работали все существующие сценарии.
        // Если цехов несколько — при отправке нужно выбрать конкретный.
        $available = ProjectStage::workshopsFor($deal->company_id ? (int) $deal->company_id : null);
        $workshop = $request->string('workshop')->toString() ?: null;
        if (count($available) > 1 && ! in_array($workshop, $available, true)) {
            return back()->with('error', 'Выберите цех: '.implode(' или ', $available).'.');
        }
        $project = $projects->createFromDeal($deal, $workshop);
        $deal->update(['status' => 'closed', 'closed_at' => now()]);

        return back()->with('success', 'Отправлено в цех: '.$project->number.'.');
    }
}
