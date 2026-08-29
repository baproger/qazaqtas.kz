<?php

namespace App\Robots\Actions;

use App\Models\Deal;
use App\Models\StageRobotRun;
use App\Robots\RobotActionInterface;

class ChangeFieldAction implements RobotActionInterface
{
    /** Поля, которые роботу можно менять. Деньги и этап — нет. */
    public const FIELDS = ['status' => 'Статус', 'branch' => 'Филиал', 'source' => 'Источник', 'note' => 'Заметка', 'description' => 'Описание', 'deadline' => 'Срок', 'deal_type' => 'Тип сделки'];

    public static function type(): string
    {
        return 'change_field';
    }

    public static function label(): string
    {
        return 'Изменить поле';
    }

    public static function schema(): array
    {
        return [
            ['key' => 'field', 'label' => 'Поле', 'type' => 'select', 'options' => self::FIELDS, 'required' => true],
            ['key' => 'value', 'label' => 'Значение', 'type' => 'text', 'required' => true, 'hint' => 'Можно с плейсхолдерами; для срока — дата или +N (дней от сегодня)'],
        ];
    }

    public function handle(Deal $deal, array $payload, StageRobotRun $run): array
    {
        $field = (string) ($payload['field'] ?? '');
        if (! array_key_exists($field, self::FIELDS)) {
            throw new \RuntimeException("Поле «{$field}» роботу менять нельзя.");
        }
        $value = $payload['value'] ?? null;
        if ($field === 'deadline' && is_string($value) && preg_match('/^\+(\d+)$/', trim($value), $m)) {
            $value = now()->addDays((int) $m[1])->toDateString();
        }
        $deal->update([$field => $value]);

        return [$field => $value];
    }
}
