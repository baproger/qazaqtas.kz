<?php

namespace App\Notifications;

use App\Models\Deal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class DealStageChanged extends Notification
{
    use Queueable;

    public function __construct(public Deal $deal, public string $stageName) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'deal_stage_changed',
            'title' => 'Сделка перешла на новый этап',
            'message' => "{$this->deal->number}: {$this->stageName}",
            'deal_id' => $this->deal->id,
        ];
    }
}
