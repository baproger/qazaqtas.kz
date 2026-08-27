<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Storage;

/**
 * Позиция сделки: один товар с собственным количеством, единицей и ценой.
 *
 * Название, единица и цена — СНИМОК каталога на момент продажи: товар потом
 * переименуют или переоценят, а сумма закрытой сделки меняться не должна.
 */
class DealItem extends Model
{
    protected $fillable = ['deal_id', 'product_id', 'name', 'unit', 'quantity', 'price', 'amount', 'sort', 'finished_at', 'finished_by'];

    protected $casts = [
        'finished_at' => 'datetime',
        'quantity' => 'decimal:2',
        'price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    /**
     * Фото этой позиции: «вот эта плитка выглядит так».
     *
     * Снимок принадлежит товару, а не сделке целиком — в цехе по нему сверяют
     * отливку, и общая куча снимков на весь заказ этого не даёт.
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    protected static function booted(): void
    {
        // Товар убрали из заказа — его снимкам места не осталось. Чистим и
        // файлы: иначе диск копит картинки, на которые никто уже не смотрит.
        static::deleting(function (DealItem $item) {
            foreach ($item->documents as $document) {
                Storage::disk('local')->delete($document->file_path);
                $document->delete();
            }
        });
    }

    /** Кто отметил товар законченным. */
    public function finisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finished_by');
    }

    /** Работа по этому товару кончилась — цех его больше не делает. */
    public function isFinished(): bool
    {
        return $this->finished_at !== null;
    }

    /** Наряды цеха по этой позиции: из них считается сделанный объём. */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
