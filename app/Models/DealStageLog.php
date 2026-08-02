<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * История этапов сделки: вход на этап / уход / длительность / кто перевёл.
 * Пишется автоматически из хуков Deal (создание / смена этапа / в цех / отмена).
 */
class DealStageLog extends Model
{
    protected $fillable = ['deal_id', 'deal_stage_id', 'stage_name', 'moved_by', 'entered_at', 'left_at', 'duration_seconds'];

    protected $casts = ['entered_at' => 'datetime', 'left_at' => 'datetime'];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function mover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }

    /** Открыть тайминг текущего этапа сделки. */
    public static function open(Deal $deal): void
    {
        static::create([
            'deal_id' => $deal->id,
            'deal_stage_id' => $deal->deal_stage_id,
            'stage_name' => DealStage::find($deal->deal_stage_id)?->name ?? '—',
            'moved_by' => auth()->id(),
            'entered_at' => now(),
        ]);
    }

    /** Закрыть открытый тайминг (уход с этапа / в цех / отмена / удаление). */
    public static function closeOpen(Deal $deal): void
    {
        $log = static::where('deal_id', $deal->id)->whereNull('left_at')->latest('entered_at')->first();
        if ($log) {
            $log->update(['left_at' => now(), 'duration_seconds' => (int) abs(now()->diffInSeconds($log->entered_at))]);
        }
    }
}
