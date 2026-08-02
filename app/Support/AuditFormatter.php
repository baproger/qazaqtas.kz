<?php

namespace App\Support;

use Illuminate\Support\Collection;

class AuditFormatter
{
    /**
     * Replace raw id values in audit logs with human-readable labels.
     *
     * @param  Collection  $logs
     * @param  array<string, \Illuminate\Support\Collection|array>  $maps  field => (id => label)
     */
    public static function humanize(Collection $logs, array $maps): Collection
    {
        return $logs->map(function ($log) use ($maps) {
            foreach ($maps as $field => $map) {
                if ($log->field_name === $field) {
                    $log->old_value = $log->old_value !== null && $log->old_value !== ''
                        ? ($map[$log->old_value] ?? $log->old_value) : '—';
                    $log->new_value = $log->new_value !== null && $log->new_value !== ''
                        ? ($map[$log->new_value] ?? $log->new_value) : '—';
                }
            }

            return $log;
        });
    }
}
