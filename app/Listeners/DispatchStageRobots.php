<?php

namespace App\Listeners;

use App\Events\DealMovedToStage;
use App\Models\StageRobot;
use App\Models\StageRobotRun;
use App\Robots\Conditions;
use App\Robots\Runner;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Слушает переход сделки, отбирает роботов этапа, пишет запуски.
 * Мгновенные параллельные — выполняет сразу; отложенные и цепочки ждут
 * `robots:run-due` (каждую минуту), который не зависит от очереди.
 */
class DispatchStageRobots
{
    public function __construct(private Runner $runner) {}

    public function handle(DealMovedToStage $event): void
    {
        $deal = $event->deal;
        $companyId = $deal->company_id ? (int) $deal->company_id : null;

        $robots = StageRobot::query()->where('is_active', true)
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'))
            ->where(function ($q) use ($event) {
                $q->where(fn ($w) => $w->where('trigger', 'enter')->where(fn ($s) => $s->where('stage_id', $event->to->id)->orWhereNull('stage_id')));
                if ($event->from) {
                    $q->orWhere(fn ($w) => $w->where('trigger', 'leave')->where('stage_id', $event->from->id));
                }
            })
            ->orderBy('sort')->orderBy('id')->get();

        $chainBefore = false;
        $immediate = [];
        foreach ($robots as $robot) {
            if (! Conditions::pass($robot->conditions, $deal)) {
                continue;
            }
            $sequential = $robot->sequence === 'sequential';
            $status = $sequential && $chainBefore ? 'waiting' : 'queued';
            try {
                $run = StageRobotRun::create([
                    'robot_id' => $robot->id, 'deal_id' => $deal->id, 'transition_id' => $event->transitionId,
                    'stage_id_at_trigger' => $robot->trigger === 'enter' ? $event->to->id : $event->from?->id,
                    'status' => $status,
                    'scheduled_at' => $robot->delay_seconds > 0 ? now()->addSeconds($robot->delay_seconds) : null,
                ]);
            } catch (UniqueConstraintViolationException) {
                continue; // тот же робот на тот же переход уже записан
            }
            if ($sequential) {
                $chainBefore = true;
            }
            if ($status === 'queued' && $robot->delay_seconds === 0 && ! $sequential) {
                $immediate[] = $run;
            } elseif ($status === 'queued' && $robot->delay_seconds === 0 && $sequential) {
                $immediate[] = $run; // первый в цепочке — сразу; следующие подберёт run-due
            }
        }

        foreach ($immediate as $run) {
            $this->runner->run($run);
        }
    }
}
