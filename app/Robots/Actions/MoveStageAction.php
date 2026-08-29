<?php

namespace App\Robots\Actions;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\StageRobotRun;
use App\Robots\RobotActionInterface;
use App\Services\StageTransitionService;

class MoveStageAction implements RobotActionInterface
{
    /** Защита от зацикливания: робот → этап → робот → этап … */
    public static int $depth = 0;

    public static function type(): string
    {
        return 'move_stage';
    }

    public static function label(): string
    {
        return 'Перевести на этап';
    }

    public static function schema(): array
    {
        return [
            ['key' => 'stage_id', 'label' => 'Этап', 'type' => 'stage', 'required' => true, 'hint' => 'Условия перехода этапа действуют и для робота'],
        ];
    }

    public function handle(Deal $deal, array $payload, StageRobotRun $run): array
    {
        if (self::$depth >= 3) {
            throw new \RuntimeException('Цепочка автопереходов слишком длинная (защита от зацикливания).');
        }
        $target = DealStage::findOrFail((int) $payload['stage_id']);
        self::$depth++;
        try {
            app(StageTransitionService::class)->moveToStage($deal, $target);
        } finally {
            self::$depth--;
        }

        return ['stage' => $target->name];
    }
}
