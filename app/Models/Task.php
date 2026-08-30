<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\AccessScope;
use App\Support\LiveStamp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Task extends Model
{
    use Auditable;
    use HasFactory, SoftDeletes;

    public const STATUSES = ['new' => 'Новая', 'in_progress' => 'В работе', 'review' => 'Проверка', 'done' => 'Готово', 'canceled' => 'Отменена'];

    public const TYPES = ['crm_deal' => 'По сделке', 'erp_process' => 'Процесс ERP', 'corporate' => 'Корпоративная'];

    public const PRIORITIES = ['low' => 'Низкий', 'medium' => 'Обычный', 'high' => 'Высокий'];

    /** Статусы, в которых задача закрыта и не держит гейт. */
    public const CLOSED = ['done', 'canceled'];

    protected $fillable = [
        'taskable_type', 'taskable_id', 'type', 'title', 'description', 'note',
        'assignee_id', 'creator_id', 'priority', 'status',
        'position', 'start_date', 'due_date', 'parent_task_id', 'checklist', 'completed_at', 'overdue_notified_at',
    ];

    protected $casts = [
        'checklist' => 'array',
        'start_date' => 'datetime',
        'due_date' => 'datetime',
        'completed_at' => 'datetime',
        'overdue_notified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Тип задачи следует за привязкой: сделка → CRM, заказ цеха → процесс ERP,
        // без привязки (или личная) → корпоративная.
        static::saving(function (Task $task) {
            $task->type = match ($task->taskable_type) {
                'deal' => 'crm_deal',
                'project' => 'erp_process',
                default => 'corporate',
            };
        });
        // Живые обновления: сдвигаем штамп у всех, кого задача касается
        // (и у прежнего исполнителя, если его сменили).
        $bump = fn (Task $t) => LiveStamp::bump(
            [$t->assignee_id, $t->creator_id, $t->getOriginal('assignee_id')], 'tasks');
        static::saved($bump);
        static::deleted($bump);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED, true);
    }

    /**
     * Задачи, которые человек видит на странице задач: свои (исполнитель или
     * автор) всегда; шире — по области права task.viewAny (отдел / все).
     */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $scope = AccessScope::for($user, 'task.viewAny');
        if ($scope === AccessScope::ALL) {
            return $q;
        }
        $ids = in_array($scope, [AccessScope::DEPARTMENT, AccessScope::DEPARTMENT_TREE], true)
            ? AccessScope::peerIds($user, $scope)
            : [$user->id];

        return $q->where(fn ($w) => $w->whereIn('assignee_id', $ids)->orWhere('creator_id', $user->id));
    }

    public function taskable(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }
}
