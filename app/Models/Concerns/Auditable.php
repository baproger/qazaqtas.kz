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
    // access_code — хэш кода входа (User): даже хэшированному секрету не
    // место на странице «Журнал действий». PHP не даёт модели переопределить
    // свойство трейта другим значением, поэтому исключение живёт здесь.
    protected array $auditExclude = ['updated_at', 'created_at', 'deleted_at', 'remember_token', 'password', 'access_code'];

    public static function bootAuditable(): void
    {
        // При создании и удалении пишем СНИМОК записи целиком: раньше в
        // журнале стояло голое «создано», и что именно ввели в модальном
        // окне — сумму, дату, кому — приходилось искать по самой записи.
        // Для удалённой записи искать уже негде.
        static::created(fn ($model) => $model->writeAudit('created', AuditLog::SNAPSHOT, null, $model->auditSnapshot()));
        static::deleted(fn ($model) => $model->writeAudit('deleted', AuditLog::SNAPSHOT, $model->auditSnapshot(), null));
        static::updated(function ($model) {
            foreach ($model->getChanges() as $field => $new) {
                if (in_array($field, $model->auditExclude, true)) {
                    continue;
                }
                $model->writeAudit('updated', $field, Arr::get($model->getOriginal(), $field), $new);
            }
        });
    }

    /**
     * Значимые поля записи для журнала.
     *
     * @return array<string, mixed>
     */
    protected function auditSnapshot(): array
    {
        return collect(Arr::except($this->getAttributes(), $this->auditExclude))
            // Пустые поля в журнале только мешают читать, что ввели.
            ->reject(fn ($v) => $v === null || $v === '')
            ->all();
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
