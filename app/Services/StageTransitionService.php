<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StageTransitionService
{
    /**
     * Move a deal to the target stage, enforcing the current stage's checklist,
     * and the special-stage flow: «Акт утверждение» (только из цеха) → «ЭСФ» →
     * «Оплата успешно» (полная оплата НЕ обязательна — допускается частичная). Спец-этапы ищутся по названию,
     * а не по позиции — этапы можно перемещать в настройках.
     *
     * @throws ValidationException when a gate is unmet.
     */
    public function moveToStage(Deal $deal, DealStage $target): Deal
    {
        return DB::transaction(function () use ($deal, $target) {
            $deal->loadMissing('stage');
            $current = $deal->stage;

            $isForward = $current && $target->order > $current->order;

            // Спец-этапы ВОРОНКИ КОМПАНИИ этой сделки (у BAIA и ASU свои).
            $companyId = $deal->company_id ? (int) $deal->company_id : null;
            if ($target->company_id && (int) $target->company_id !== $companyId) {
                throw ValidationException::withMessages([
                    'stage' => 'Этап принадлежит воронке другой компании.',
                ]);
            }
            $actStage = DealStage::actStage($companyId);
            $esfStage = DealStage::esfStage($companyId);
            $wonStage = DealStage::wonStage($companyId);
            // Этап перед «Оплата успешно»: ЭСФ, если он есть, иначе Акт.
            $preWon = $esfStage ?? $actStage;

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

            // Этапы «Акт утверждение», «ЭСФ», «Оплата успешно» двигает ТОЛЬКО
            // бухгалтер (financist) или админ. Менеджер довозит сделку ДО акта
            // (Сборка → Акт), дальше — не может; директор тоже не двигает.
            $user = auth()->user();
            $accountant = ! $user || $user->hasAnyRole(['admin', 'financist']);
            $postActIds = collect([$actStage?->id, $esfStage?->id, $wonStage?->id])->filter();
            if (! $accountant && $current && $postActIds->contains($current->id)) {
                throw ValidationException::withMessages([
                    'stage' => 'После «Акт утверждение» сделку двигает только бухгалтер или админ.',
                ]);
            }
            if (! $accountant && $postActIds->contains($target->id) && (! $actStage || $target->id !== $actStage->id)) {
                throw ValidationException::withMessages([
                    'stage' => 'Этапы «ЭСФ» и «Оплата успешно» переводит только бухгалтер или админ.',
                ]);
            }

            if ($esfStage && $target->id === $esfStage->id && (! $current || ! $actStage || $current->id !== $actStage->id)) {
                throw ValidationException::withMessages([
                    'stage' => 'На «ЭСФ» можно перейти только с этапа «Акт утверждение».',
                ]);
            }
            if ($wonStage && $target->id === $wonStage->id && (! $current || ! $preWon || $current->id !== $preWon->id)) {
                throw ValidationException::withMessages([
                    'stage' => 'Сначала «'.($preWon?->name ?? 'Акт утверждение').'», затем «Оплата успешно».',
                ]);
            }
            // Полная оплата для «Оплата успешно» НЕ требуется (правило от 18.07.2026):
            // сделку можно закрыть успешной и с частичной оплатой — остаток
            // виден в дебиторке на Финансах.

            $deal->deal_stage_id = $target->id;

            $deal->save();

            // Вход на этап с настроенным гейтом → задача исполнителям
            // выбранной роли (текст/роль/срок задаются в Настройки → Этапы).
            if ($target->hasGate()) {
                $this->createGateTask($deal, $target);
            }

            if ($deal->responsible_user_id) {
                $deal->responsible?->notify(new \App\Notifications\DealStageChanged($deal, $target->name));
            }

            return $deal->fresh(['stage', 'project']);
        });
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

        $assignees = User::where('is_active', true)->role($stage->gate_task_role ?: 'financist')->get();
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
            $assignee->notify(new \App\Notifications\TaskAssigned($task));
        }
    }
}
