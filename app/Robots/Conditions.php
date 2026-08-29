<?php

namespace App\Robots;

use App\Models\Deal;

/**
 * Условия робота: {"all": [{field, op, value}, …]} — все должны выполниться;
 * пусто — робот срабатывает всегда. Поля — колонки сделки.
 */
final class Conditions
{
    public const OPS = ['==', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'contains', 'empty', 'not_empty'];

    public const FIELDS = [
        'budget' => 'Сумма', 'deal_type' => 'Тип сделки', 'status' => 'Статус', 'branch' => 'Филиал', 'source' => 'Источник',
        'company_name' => 'Компания', 'client_name' => 'Клиент', 'responsible_user_id' => 'Ответственный (id)',
        'foreman_id' => 'Бригадир (id)', 'deadline' => 'Срок', 'partner_pct' => 'Доля партнёра, %', 'area_m2' => 'Площадь, м²',
    ];

    /** @param array<string, mixed>|null $conditions */
    public static function pass(?array $conditions, Deal $deal): bool
    {
        foreach ((array) ($conditions['all'] ?? []) as $c) {
            if (! self::one($c, $deal)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $c */
    private static function one(array $c, Deal $deal): bool
    {
        $field = (string) ($c['field'] ?? '');
        if (! array_key_exists($field, self::FIELDS)) {
            return false;
        }
        $actual = $deal->getAttribute($field);
        $expected = $c['value'] ?? null;
        $num = fn ($v) => is_numeric($v) ? (float) $v : $v;

        return match ($c['op'] ?? '==') {
            '==' => $num($actual) == $num($expected),
            '!=' => $num($actual) != $num($expected),
            '>' => (float) $actual > (float) $expected,
            '>=' => (float) $actual >= (float) $expected,
            '<' => (float) $actual < (float) $expected,
            '<=' => (float) $actual <= (float) $expected,
            'in' => in_array((string) $actual, array_map('trim', explode(',', (string) $expected)), true),
            'not_in' => ! in_array((string) $actual, array_map('trim', explode(',', (string) $expected)), true),
            'contains' => $expected !== null && $expected !== '' && str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'empty' => $actual === null || $actual === '' || $actual === 0.0,
            'not_empty' => ! ($actual === null || $actual === ''),
            default => false,
        };
    }
}
