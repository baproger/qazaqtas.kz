<?php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TaskAssigned extends Notification
{
    use Queueable;

    public function __construct(public Task $task) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'task_assigned',
            'title' => 'Вам назначена задача',
            'message' => $this->task->title.($this->task->creator ? ' — от '.$this->task->creator->name : ''),
            'task_id' => $this->task->id,
            'url' => route('tasks.show', $this->task->id),
            'from' => $this->task->creator?->name,
        ];
    }
}
