<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Движение склада готовой продукции: единственный источник правды по остатку.
 *
 * Остаток — это сумма движений, а не число, которое кто-то поправил. У каждой
 * строки есть источник: наряд, позиция сделки, инвентаризация. Спросили
 * «откуда взялись эти 800 м²» — ответ виден построчно.
 */
class StockMovement extends Model
{
    /** Приход из подтверждённой выработки по плану. */
    public const PRODUCTION_IN = 'production_in';

    /** Списание в сделку и возврат из неё. */
    public const DEAL_OUT = 'deal_out';

    public const DEAL_RETURN = 'deal_return';

    /** Инвентаризация: правит только руководство, с обязательной причиной. */
    public const MANUAL_ADJUST = 'manual_adjust';

    /** Сторно ранее подтверждённого прихода. */
    public const REVERSAL = 'reversal';

    protected $fillable = [
        'product_id', 'company_id', 'qty', 'type',
        'source_type', 'source_id', 'created_by', 'note',
    ];

    protected $casts = ['qty' => 'decimal:2'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Подпись типа движения для ленты склада. */
    public function label(): string
    {
        return match ($this->type) {
            self::PRODUCTION_IN => 'Произведено',
            self::DEAL_OUT => 'В сделку',
            self::DEAL_RETURN => 'Возврат из сделки',
            self::MANUAL_ADJUST => 'Корректировка',
            self::REVERSAL => 'Сторно',
            default => $this->type,
        };
    }
}
