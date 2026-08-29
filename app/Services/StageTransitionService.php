<?php

namespace App\Services;

use App\Events\DealMovedToStage;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Role;
use App\Notifications\DealStageChanged;
use App\Notifications\TaskAssigned;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StageTransitionService
{
    /**
     * Move a deal to the target stage, enforcing the current stage's checklist,
     * and the special-stage flow: «Акт утверждение» (только из цеха) → «ЭСФ» →
     * «Оплата успешно» (полная оплата НЕ обязательна — допускается частичная).
     * Спец-этапы определяются СИСТЕМНЫМ ТИПОМ (stage_type) из админки: этап
     * можно переименовать и переставить, логика за ним не поедет. Нет этапа с
     * типом — соответствующее правило просто не действует.
     *
     * @throws ValidationException when a gate is unmet.
     */
    public function moveToStage(Deal $deal, DealStage $target): Deal
    {
        return DB::transaction(function () use ($deal, $target) {
            $deal->loadMissing('stage');
            $current = $deal->stage;

            $isForward = $current && $target->order > $current->order;

            // Спец-этапы ВОРОНКИ ФИРМЫ этой сделки (у каждой фирмы свои).
            $companyId = $deal->company_id ? (int) $deal->company_id : null;
            if ($target->company_id && (int) $target->company_id !== $companyId) {
                throw ValidationException::withMessages([
                    'stage' => 'Этап принадлежит воронке другой компании.',
                ]);
            }

            // Гейт этапа настраивается в админке (Настройки → Этапы): пока
            // гейт-задача не закрыта, сделка дальше не идёт. Прочие открытые
            // задачи сделку не держат. Для этапов без гейта работает общий
            // checklist-гейт (все задачи должны быть закрыты).
            if ($isForward && $current) {
                if ($current->hasGate()) {
                    $open = $deal->tasks()->where('title', 'like', $current->gate_task_title.'%')->where('status', '!=', 'done')->count();
                    if ($open > 0) {
                        throw ValidationException::withMessages([
                            'stage' => "Сначала закройте задачу «{$current->gate_task_title}…» — гейт этапа «{$current->name}».",
                        ]);
                    }
                } elseif (! empty($current->checklist)) {
                    $openTasks = $deal->tasks()->where('status', '!=', 'done')->count();
                    if ($openTasks > 0) {
                        throw ValidationException::withMessages([
                            'stage' => "Нельзя перейти на следующий этап: на этапе «{$current->name}» есть незавершённые задачи ({$openTasks}).",
                        ]);
                    }
                }
            }

            // Этап-ворота в цех: вперёд с него сделка уходит ТОЛЬКО кнопкой
            // «В цех». Иначе её можно было перевести на следующий этап руками,
            // и она «проходила производство», не побывав в цехе. Обратно на
            // воронку её вернёт сам цех, закончив заказ (ProjectService).
            $shopGate = DealStage::ofType('shop_gate', $companyId);
            if ($isForward && $current && $shopGate && $current->id === $shopGate->id) {
                throw ValidationException::withMessages([
                    'stage' => "С этапа «{$current->name}» сделка уходит только кнопкой «В цех» — она вернётся сама, когда цех закончит заказ.",
                ]);
            }

            // Требование документа на этапе (Настройки → Этапы): пока к сделке
            // не прикреплён ни один файл, дальше она не идёт. Проверяем только
            // движение вперёд — возврат назад не должен блокироваться.
            if ($isForward && $current?->requires_document && ! $deal->documents()->exists()) {
                throw ValidationException::withMessages([
                    'stage' => "Этап «{$current->name}»: прикрепите документ к сделке — без него дальше нельзя.",
                ]);
            }

            // ---- Правила этапа из конструктора (Настройки → Этапы) ----
            // Раньше это были зашитые в код правила акта/ЭСФ/оплаты; теперь
            // тот же смысл собирает владелец, а здесь только проверка.
            $this->enforceRules($deal, $current, $target, $isForward);

            $deal->deal_stage_id = $target->id;

            $deal->save();

            // Роботы этапов слушают это событие (App\Listeners\DispatchStageRobots).
            // Логика перехода о них не знает — только сообщает.
            DB::afterCommit(fn () => event(new DealMovedToStage($deal, $current, $target, auth()->user())));

            // Вход на этап с настроенным гейтом → задача исполнителям
            // выбранной роли (текст/роль/срок задаются в Настройки → Этапы).
            if ($target->hasGate()) {
                $this->createGateTask($deal, $target);
            }

            if ($deal->responsible_user_id) {
                $deal->responsible?->notify(new DealStageChanged($deal, $target->name));
            }

            return $deal->fresh(['stage', 'project']);
        });
    }

    /**
     * Проверка правил конструктора. Админ — вне ролевых ограничений, как и
     * везде (Gate::before); требования к сделке действуют и для него.
     *
     * @throws ValidationException
     */
    private function enforceRules(Deal $deal, ?DealStage $current, DealStage $target, bool $isForward): void
    {
        $user = auth()->user();
        $roleFree = ! $user || $user->hasRole('admin');
        $has = fn (array $roles) => $roleFree || $user->hasAnyRole($roles);

        $fail = fn (string $msg) => throw ValidationException::withMessages(['stage' => $msg]);

        // Уйти с текущего этапа вперёд — кто может.
        if ($isForward && $current) {
            $cur = $current->effectiveRules();
            if ($cur['leave_roles'] !== [] && ! $has($cur['leave_roles'])) {
                $fail('После «'.$current->name.'» сделку двигает: '.$this->roleNames($cur['leave_roles']).'.');
            }
            $req = $cur['require'];
            if (! empty($req['invoice']) && ! $deal->invoices()->where('status', '!=', 'cancelled')->exists()) {
                $fail('Этап «'.$current->name.'»: сначала выставьте счёт.');
            }
            if (($req['payment'] ?? 'none') !== 'none') {
                $paid = (float) $deal->invoices()->where('status', '!=', 'cancelled')->get()->sum('paid_amount');
                if ($req['payment'] === 'partial' && $paid <= 0) {
                    $fail('Этап «'.$current->name.'»: нужна хотя бы частичная оплата.');
                }
                if ($req['payment'] === 'full' && $paid + 0.005 < (float) $deal->budget) {
                    $fail('Этап «'.$current->name.'»: нужна полная оплата ('.number_format((float) $deal->budget, 0, '.', ' ').' ₸).');
                }
            }
            if (! empty($req['items_done']) && $deal->items()->whereNull('finished_at')->exists()) {
                $fail('Этап «'.$current->name.'»: не все позиции сделаны в цехе.');
            }
        }

        // Перевести на целевой этап — кто может и откуда.
        $tgt = $target->effectiveRules();
        if ($tgt['enter_roles'] !== [] && ! $has($tgt['enter_roles'])) {
            $fail('Этап «'.$target->name.'» переводит: '.$this->roleNames($tgt['enter_roles']).'.');
        }
        if ($tgt['from_stages'] !== [] && (! $current || ! in_array($current->id, array_map('intval', $tgt['from_stages']), true))) {
            $names = DealStage::whereIn('id', $tgt['from_stages'])->pluck('name')->implode('», «');
            $fail('На «'.$target->name.'» можно перейти только с «'.$names.'».');
        }
    }

    /** @param  array<int, string>  $roles */
    private function roleNames(array $roles): string
    {
        $labels = Role::whereIn('name', $roles)->get()->map->title()->all();

        return implode(', ', $labels ?: $roles).' или админ';
    }

    /**
     * Гейт-задача этапа: настраивается в админке (текст, роль исполнителя,
     * срок в днях). Ставится КАЖДОМУ активному сотруднику роли; пока хотя бы
     * одна не закрыта — сделка не двигается дальше (см. moveToStage); при
     * просрочке tasks:notify-overdue уведомит исполнителя.
     */
    public function createGateTask(Deal $deal, DealStage $stage): void
    {
        $title = $stage->gate_task_title.' по сделке '.$deal->number;
        if ($deal->tasks()->where('title', $title)->where('status', '!=', 'done')->exists()) {
            return; // задача уже висит — не дублируем
        }

        // Кому — решает настройка этапа: ответственному (по умолчанию),
        // руководителю его отдела, бригадиру или всем с ролью. Раньше задача
        // уходила КАЖДОМУ с ролью — три менеджера получали три одинаковые.
        $assignees = $stage->gateAssignees($deal);
        foreach ($assignees as $assignee) {
            $task = $deal->tasks()->create([
                'title' => $title,
                'status' => 'new',
                'priority' => 'high',
                'assignee_id' => $assignee->id,
                'creator_id' => $deal->responsible_user_id ?? $assignee->id,
                'start_date' => now(),
                'due_date' => now()->addDays((int) $stage->gate_task_days),
            ]);
            $assignee->notify(new TaskAssigned($task));
        }
    }
}
