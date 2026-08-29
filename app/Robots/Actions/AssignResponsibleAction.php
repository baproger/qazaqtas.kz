<?php

namespace App\Robots\Actions;

use App\Models\Deal;
use App\Models\StageRobotRun;
use App\Models\User;
use App\Robots\RobotActionInterface;
use App\Support\RoleTraits;

class AssignResponsibleAction implements RobotActionInterface
{
    public static function type(): string
    {
        return 'assign_responsible';
    }

    public static function label(): string
    {
        return 'Сменить ответственного';
    }

    public static function schema(): array
    {
        return [
            ['key' => 'user_id', 'label' => 'Сотрудник', 'type' => 'user'],
            ['key' => 'role', 'label' => 'Или первому активному с ролью', 'type' => 'roles', 'hint' => 'Если сотрудник не выбран'],
        ];
    }

    public function handle(Deal $deal, array $payload, StageRobotRun $run): array
    {
        $user = ! empty($payload['user_id']) ? User::where('is_active', true)->find($payload['user_id']) : null;
        if (! $user && ! empty($payload['role'])) {
            $user = RoleTraits::users((array) $payload['role'])->where('is_active', true)->orderBy('id')->first();
        }
        if (! $user) {
            throw new \RuntimeException('Некому назначить: сотрудник не найден.');
        }
        $deal->update(['responsible_user_id' => $user->id]);

        return ['responsible' => $user->name];
    }
}
