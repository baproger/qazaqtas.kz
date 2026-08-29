<?php

namespace App\Robots;

use App\Models\Deal;
use App\Models\DealStage;

/**
 * {{deal.number}}, {{deal.budget|money}}, {{stage.name}}, {{company.name}},
 * {{responsible.name}}, {{deadline|date}} — подстановка в тексты действий.
 */
final class Placeholders
{
    /** @return array<string, mixed> */
    public static function context(Deal $deal, ?DealStage $stage = null): array
    {
        $deal->loadMissing(['stage', 'responsible', 'company']);

        return [
            'deal' => $deal->only(['id', 'number', 'name', 'company_name', 'client_name', 'address', 'contact_name', 'contact_phone', 'budget', 'deadline', 'deal_type', 'status', 'description', 'note', 'source', 'branch']),
            'stage' => ['name' => ($stage ?? $deal->stage)?->name],
            'company' => ['name' => $deal->company?->name],
            'responsible' => ['name' => $deal->responsible?->name, 'email' => $deal->responsible?->email],
            'url' => ['deal' => route('deals.show', $deal->id)],
        ];
    }

    public static function render(mixed $value, array $ctx): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => self::render($v, $ctx), $value);
        }
        if (! is_string($value)) {
            return $value;
        }

        return preg_replace_callback('/\{\{\s*([\w.]+)(?:\|(\w+))?\s*\}\}/u', function ($m) use ($ctx) {
            $v = data_get($ctx, $m[1]);
            if ($v === null) {
                return '';
            }

            return match ($m[2] ?? '') {
                'money' => number_format((float) $v, 0, '.', ' ').' ₸',
                'date' => (string) (is_string($v) ? substr($v, 0, 10) : $v?->format('d.m.Y')),
                default => (string) $v,
            };
        }, $value);
    }
}
