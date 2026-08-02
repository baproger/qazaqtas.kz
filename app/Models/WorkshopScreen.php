<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ТВ-экран цеха: код доступа открывает канбан только своего цеха. */
class WorkshopScreen extends Model
{
    protected $fillable = ['company_id', 'workshop', 'kind', 'code', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Уникальный числовой код (6 цифр). */
    public static function freshCode(): string
    {
        do {
            $code = (string) random_int(100000, 999999);
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
