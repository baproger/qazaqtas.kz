<?php

namespace App\Robots;

use App\Models\Deal;
use App\Models\StageRobotRun;

/**
 * Действие робота. Новый тип = один класс + регистрация в ActionRegistry.
 * `schema()` описывает поля формы — UI строит её сам.
 */
interface RobotActionInterface
{
    public static function type(): string;

    public static function label(): string;

    /** @return array<int, array{key: string, label: string, type: string, options?: array<string, string>, required?: bool, hint?: string}> */
    public static function schema(): array;

    /**
     * @param  array<string, mixed>  $payload  уже с подставленными плейсхолдерами
     * @return array<string, mixed> что сделали — в журнал
     */
    public function handle(Deal $deal, array $payload, StageRobotRun $run): array;
}
