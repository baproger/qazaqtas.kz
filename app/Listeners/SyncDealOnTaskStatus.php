<?php

namespace App\Listeners;

use App\Events\TaskStatusUpdated;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\Task;
use App\Services\StageTransitionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Задача по сделке закрыта → сделка идёт дальше, если владелец включил у
 * этапа правило «автопереход после гейта» и все гейт-задачи закрыты.
 * Переход идёт через StageTransitionService — с теми же условиями и роботами.
 */
class SyncDealOnTaskStatus
{
    public function __construct(private StageTransitionService $transitions) {}

    public function handle(TaskStatusUpdated $event): void
    {
        $task = $event->task;
        if ($task->type !== 'crm_deal' || $event->to !== 'done') {
            return;
        }
        $deal = Deal::find($task->taskable_id);
        $stage = $deal?->stage;
        if (! $deal || ! $stage || ! $stage->hasGate() || empty($stage->effectiveRules()['advance_on_gate'])) {
            return;
        }
        if (! str_starts_with($task->title, $stage->gate_task_title)) {
            return; // закрыли не гейт-задачу
        }
        $stillOpen = $deal->tasks()->where('title', 'like', $stage->gate_task_title.'%')
            ->whereNotIn('status', Task::CLOSED)->exists();
        if ($stillOpen) {
            return;
        }

        $next = DealStage::funnel($deal->company_id ? (int) $deal->company_id : null)
            ->where('order', '>', $stage->order)->sortBy('order')->first();
        if (! $next) {
            return;
        }

        try {
            DB::transaction(fn () => $this->transitions->moveToStage($deal, $next));
        } catch (ValidationException) {
            // Условия следующего этапа не выполнены (оплата, документ…) —
            // автопереход молча не случается; человек переведёт вручную.
        }
    }
}
