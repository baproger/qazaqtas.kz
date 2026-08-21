<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Сменный наряд: что бригада сделала за день.
 *
 * Пока наряд не подтверждён мастером, он бонуса не даёт — иначе выработку
 * можно было бы приписать себе самому.
 */
class WorkOrder extends Model
{
    use Auditable;

    protected $fillable = [
        'company_id', 'brigade_id', 'project_id', 'date', 'product',
        'status', 'created_by', 'confirmed_by', 'confirmed_at', 'note',
    ];

    protected $casts = ['date' => 'date', 'confirmed_at' => 'datetime'];

    public function brigade(): BelongsTo
    {
        return $this->belongsTo(Brigade::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WorkOrderLine::class);
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }
}
