<?php

namespace App\Robots;

use App\Robots\Actions\AssignResponsibleAction;
use App\Robots\Actions\ChangeFieldAction;
use App\Robots\Actions\CreateTaskAction;
use App\Robots\Actions\MoveStageAction;
use App\Robots\Actions\SendNotificationAction;
use App\Robots\Actions\SendWebhookAction;
use InvalidArgumentException;

/** Реестр действий: тип → класс. Добавить действие = дописать строку. */
final class ActionRegistry
{
    /** @var array<int, class-string<RobotActionInterface>> */
    private static array $actions = [
        SendNotificationAction::class,
        CreateTaskAction::class,
        AssignResponsibleAction::class,
        ChangeFieldAction::class,
        MoveStageAction::class,
        SendWebhookAction::class,
    ];

    /** @param class-string<RobotActionInterface> $class */
    public static function register(string $class): void
    {
        if (! in_array($class, self::$actions, true)) {
            self::$actions[] = $class;
        }
    }

    /** @return array<string, class-string<RobotActionInterface>> */
    public static function all(): array
    {
        $out = [];
        foreach (self::$actions as $class) {
            $out[$class::type()] = $class;
        }

        return $out;
    }

    public static function make(string $type): RobotActionInterface
    {
        $class = self::all()[$type] ?? throw new InvalidArgumentException("Неизвестное действие робота: {$type}");

        return app($class);
    }

    /** Описание для UI: тип → подпись и поля. */
    public static function describe(): array
    {
        return collect(self::all())->map(fn ($class, $type) => [
            'type' => $type, 'label' => $class::label(), 'schema' => $class::schema(),
        ])->values()->all();
    }
}
