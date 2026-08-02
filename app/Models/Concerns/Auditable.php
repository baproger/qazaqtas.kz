<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Support\Arr;

trait Auditable
{
    /**
     * Attributes never worth auditing.
     *
     * @var array<int, string>
     */
    protected array $auditExclude = ['updated_at', 'created_at', 'deleted_at', 'remember_token', 'password'];

    public static function bootAuditable(): void
    {
        static::created(fn ($model) => $model->writeAudit('created'));
        static::deleted(fn ($model) => $model->writeAudit('deleted'));
        static::updated(function ($model) {
            foreach ($model->getChanges() as $field => $new) {
                if (in_array($field, $model->auditExclude, true)) {
                    continue;
                }
                $model->writeAudit('updated', $field, Arr::get($model->getOriginal(), $field), $new);
            }
        });
    }

    protected function writeAudit(string $action, ?string $field = null, $old = null, $new = null): void
    {
        $request = request();

        AuditLog::create([
            'user_id' => auth()->id(),
            'ip' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 255),
            'table_name' => $this->getTable(),
            'record_id' => $this->getKey(),
            'action' => $action,
            'field_name' => $field,
            'old_value' => is_scalar($old) || $old === null ? $old : json_encode($old),
            'new_value' => is_scalar($new) || $new === null ? $new : json_encode($new),
            'created_at' => now(),
        ]);
    }
}
