<?php

namespace App\Robots;

use App\Models\Deal;
use App\Models\StageRobotRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Исполнитель запусков. Перед действием проверяет, что сделка всё ещё на
 * том этапе, на котором робот сработал (если робот не помечен run_if_left);
 * держит замок на сделку — роботы одной сделки не бегут одновременно.
 */
final class Runner
{
    public function run(StageRobotRun $run): void
    {
        $lock = Cache::lock('robot-deal-'.$run->deal_id, 60);
        if (! $lock->get()) {
            return; // другой робот этой сделки ещё работает — подберёт robots:run-due
        }

        try {
            $run->refresh();
            if (! in_array($run->status, ['queued', 'waiting'], true)) {
                return; // уже выполнен — идемпотентность
            }
            $robot = $run->robot;
            $deal = Deal::find($run->deal_id);

            if (! $deal || ! $robot || ! $robot->is_active) {
                $this->finish($run, 'skipped', error: $deal ? 'Робот выключен или удалён.' : 'Сделка удалена.');

                return;
            }
            if (! $robot->run_if_left && $run->stage_id_at_trigger && (int) $deal->deal_stage_id !== (int) $run->stage_id_at_trigger) {
                $this->finish($run, 'skipped', error: 'Сделка уже ушла с этапа — действие не выполнено.');

                return;
            }
            if (! Conditions::pass($robot->conditions, $deal)) {
                $this->finish($run, 'skipped', error: 'Условия больше не выполняются.');

                return;
            }

            $run->update(['status' => 'running', 'started_at' => now(), 'attempt' => $run->attempt + 1]);
            $payload = Placeholders::render($robot->action_payload ?? [], Placeholders::context($deal, $run->robot->stage));
            $output = ActionRegistry::make($robot->action_type)->handle($deal, $payload, $run);
            $this->finish($run, 'done', $output);
        } catch (Throwable $e) {
            Log::warning('Робот этапа упал', ['run' => $run->id, 'error' => $e->getMessage()]);
            $this->finish($run, 'failed', error: mb_substr($e->getMessage(), 0, 1000));
        } finally {
            $lock->release();
        }
    }

    /** Все запуски, чьё время пришло (отложенные и цепочки). */
    public function runDue(): int
    {
        $n = 0;
        StageRobotRun::query()
            ->whereIn('status', ['queued', 'waiting'])
            ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
            ->orderBy('scheduled_at')->orderBy('id')
            ->limit(200)->get()
            ->each(function (StageRobotRun $run) use (&$n) {
                if ($run->status === 'waiting' && ! $this->previousDone($run)) {
                    return; // цепочка: ждём предыдущего
                }
                $this->run($run);
                $n++;
            });

        return $n;
    }

    /** Для последовательных роботов: предыдущий в цепочке уже завершён. */
    private function previousDone(StageRobotRun $run): bool
    {
        $prev = StageRobotRun::where('transition_id', $run->transition_id)
            ->where('id', '<', $run->id)
            ->whereHas('robot', fn ($q) => $q->where('sequence', 'sequential'))
            ->orderByDesc('id')->first();

        return $prev === null || in_array($prev->status, ['done', 'skipped', 'failed'], true);
    }

    private function finish(StageRobotRun $run, string $status, array $output = [], ?string $error = null): void
    {
        $run->update(['status' => $status, 'finished_at' => now(), 'output' => $output ?: null, 'error' => $error]);
    }
}
