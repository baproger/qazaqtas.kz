<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Тайминг этапа заказа цеха: вход на этап / уход / длительность.
 * Пишется автоматически из хуков Project (created / смена этапа / завершение).
 */
class ProjectStageLog extends Model
{
    protected $fillable = ['project_id', 'project_stage_id', 'stage_name', 'entered_at', 'left_at', 'duration_seconds'];

    protected $casts = ['entered_at' => 'datetime', 'left_at' => 'datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Открыть тайминг текущего этапа заказа. */
    public static function open(Project $project): void
    {
        static::create([
            'project_id' => $project->id,
            'project_stage_id' => $project->project_stage_id,
            'stage_name' => ProjectStage::find($project->project_stage_id)?->name ?? '—',
            'entered_at' => now(),
        ]);
    }

    /** Закрыть открытый тайминг (уход с этапа / заказ завершён). */
    public static function closeOpen(Project $project): void
    {
        $log = static::where('project_id', $project->id)->whereNull('left_at')->latest('entered_at')->first();
        if ($log) {
            $log->update(['left_at' => now(), 'duration_seconds' => (int) abs(now()->diffInSeconds($log->entered_at))]);
        }
    }
}
