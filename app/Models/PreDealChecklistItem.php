<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Пункт чек-листа предварительной сделки (настраивается админом/финансистом). */
class PreDealChecklistItem extends Model
{
    protected $fillable = ['label', 'order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];
}
