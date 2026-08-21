<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Бригада производства: бригадир и состав рабочих. */
class Brigade extends Model
{
    protected $fillable = ['company_id', 'name', 'workshop', 'foreman_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function foreman(): BelongsTo
    {
        return $this->belongsTo(User::class, 'foreman_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }
}
