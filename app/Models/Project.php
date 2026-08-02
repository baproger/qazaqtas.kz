<?php

namespace App\Models;

use App\Models\Concerns\Auditable;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use Auditable;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'workshop',
        'number', 'name', 'deal_id', 'client_id', 'responsible_user_id',
        'department_id', 'project_stage_id', 'budget', 'deadline',
        'description', 'status', 'started_at', 'completed_at',
    ];

    protected $casts = [
        'budget' => 'decimal:2',
        'deadline' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Тайминг этапов: вход заказа на этап открывает таймер, смена этапа
        // закрывает старый и открывает новый, завершение/отмена — закрывает.
        static::created(function (Project $p) {
            if ($p->project_stage_id) {
                ProjectStageLog::open($p);
            }
        });
        static::updated(function (Project $p) {
            if ($p->wasChanged('project_stage_id')) {
                ProjectStageLog::closeOpen($p);
                if ($p->project_stage_id && ! in_array($p->status, ['completed', 'cancelled'], true)) {
                    ProjectStageLog::open($p);
                }
            } elseif ($p->wasChanged('status') && in_array($p->status, ['completed', 'cancelled'], true)) {
                ProjectStageLog::closeOpen($p);
            }
        });
    }

    public function stageLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ProjectStageLog::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProjectStage::class, 'project_stage_id');
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'taskable');
    }

    public function invoices(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Invoice::class, 'invoiceable');
    }

    public function expenses(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Expense::class, 'expenseable');
    }

    public function documents(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function comments(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
