<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DealStageTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = ['deal_stage_id', 'locale', 'name'];

    public function stage(): BelongsTo
    {
        return $this->belongsTo(DealStage::class, 'deal_stage_id');
    }
}
