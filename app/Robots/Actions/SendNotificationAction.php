<?php

namespace App\Robots\Actions;

use App\Models\Deal;
use App\Models\StageRobotRun;
use App\Models\User;
use App\Notifications\RobotNotification;
use App\Robots\RobotActionInterface;
use App\Support\RoleTraits;

class SendNotificationAction implements RobotActionInterface
{
    public static function type(): string
    {
        return 'send_notification';
    }

    public static function label(): string
    {
        return 'Уведомить';
    }

    public static function schema(): array
    {
        return [
            ['key' => 'roles', 'label' => 'Роли', 'type' => 'roles'],
            ['key' => 'to_responsible', 'label' => 'Ответственному за сделку', 'type' => 'bool'],
            ['key' => 'to_foreman', 'label' => 'Бригадиру сделки', 'type' => 'bool'],
            ['key' => 'title', 'label' => 'Заголовок', 'type' => 'text', 'required' => true],
            ['key' => 'text', 'label' => 'Текст', 'type' => 'textarea', 'required' => true, 'hint' => '{{deal.number}}, {{deal.company_name}}, {{deal.budget|money}}, {{stage.name}}'],
        ];
    }

    public function handle(Deal $deal, array $payload, StageRobotRun $run): array
    {
        $users = collect();
        if (! empty($payload['roles'])) {
            $users = RoleTraits::users((array) $payload['roles'])->where('is_active', true)->get();
        }
        if (! empty($payload['to_responsible']) && $deal->responsible) {
            $users->push($deal->responsible);
        }
        if (! empty($payload['to_foreman']) && $deal->foreman_id) {
            $users->push(User::find($deal->foreman_id));
        }
        $users = $users->filter()->unique('id');
        $users->each->notify(new RobotNotification($deal, (string) ($payload['title'] ?? 'Сделка'), (string) ($payload['text'] ?? '')));

        return ['notified' => $users->pluck('name')->values()->all()];
    }
}
