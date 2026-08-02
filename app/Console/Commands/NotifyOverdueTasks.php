<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Notifications\DepartmentTaskOverdue;
use App\Notifications\TaskOverdue;
use Illuminate\Console\Command;

class NotifyOverdueTasks extends Command
{
    protected $signature = 'tasks:notify-overdue';

    protected $description = 'Notify assignees of newly overdue tasks (once each).';

    public function handle(): int
    {
        $tasks = Task::query()
            ->where('status', '!=', 'done')
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->whereNull('overdue_notified_at')
            ->whereNotNull('assignee_id')
            ->with('assignee.department.head')
            ->get();

        foreach ($tasks as $task) {
            $task->assignee?->notify(new TaskOverdue($task));
            // Руководителю отдела исполнителя — чтобы просрочки не тонули.
            $head = $task->assignee?->department?->head;
            if ($head && $head->is_active && $head->id !== $task->assignee->id) {
                $head->notify(new DepartmentTaskOverdue($task, $task->assignee->name));
            }
            $task->forceFill(['overdue_notified_at' => now()])->saveQuietly();
        }

        $this->info("Notified {$tasks->count()} overdue task(s).");

        return self::SUCCESS;
    }
}
