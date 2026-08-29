<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Робот этапа: «когда → если → что». */
class StageRobot extends Model
{
    public const TRIGGERS = ['enter' => 'Сделка пришла на этап', 'leave' => 'Сделка ушла с этапа'];

    public const SEQUENCES = ['parallel' => 'Независимо от других', 'sequential' => 'После предыдущего робота'];

    protected $fillable = [
        'company_id', 'stage_id', 'trigger', 'name', 'is_active', 'sequence', 'sort',
        'delay_seconds', 'run_if_left', 'conditions', 'action_type', 'action_payload',
    ];

    protected $casts = [
        'is_active' => 'boolean', 'run_if_left' => 'boolean',
        'conditions' => 'array', 'action_payload' => 'array',
    ];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(DealStage::class, 'stage_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(StageRobotRun::class, 'robot_id');
    }
}
