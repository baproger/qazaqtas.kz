<?php

namespace App\Events;

use App\Models\Task;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Статус задачи изменился. Слушают: синхронизация со сделкой
 * (SyncDealOnTaskStatus) и браузеры участников (канал user.{id}).
 * Без настроенного BROADCAST_CONNECTION (Reverb/Pusher) вещание — no-op,
 * страница задач тогда обновляется опросом раз в 30 с.
 */
class TaskStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Task $task, public string $from, public string $to, public ?int $byUserId = null) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return collect([$this->task->assignee_id, $this->task->creator_id])->filter()->unique()
            ->map(fn ($id) => new PrivateChannel('user.'.$id))->values()->all();
    }

    public function broadcastAs(): string
    {
        return 'task.status';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['id' => $this->task->id, 'status' => $this->to, 'title' => $this->task->title];
    }
}
