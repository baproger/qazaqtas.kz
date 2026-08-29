<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Запуск робота: журнал и идемпотентность (робот × переход). */
class StageRobotRun extends Model
{
    protected $fillable = [
        'robot_id', 'deal_id', 'transition_id', 'stage_id_at_trigger', 'status',
        'scheduled_at', 'started_at', 'finished_at', 'attempt', 'error', 'output',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'output' => 'array',
    ];

    public function robot(): BelongsTo
    {
        return $this->belongsTo(StageRobot::class, 'robot_id');
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }
}
