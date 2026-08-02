<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Предварительная сделка (лот): расчёт маржи до создания настоящей сделки. */
class PreDeal extends Model
{
    protected $fillable = [
        'company_id', 'user_id', 'lot_number', 'tender_deadline', 'bin', 'customer', 'client_name', 'client_phone',
        'product', 'contract_sum', 'purchase_price', 'partner_pct', 'partner_sum',
        'delivery', 'assembly', 'commission', 'tax', 'remainder', 'margin', 'checks', 'status', 'deal_id',
    ];

    protected $casts = ['checks' => 'array', 'tender_deadline' => 'date'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** Порог «участвую»: маржа ≥ N% (по умолчанию 15). */
    public static function minMargin(): float
    {
        return (float) Setting::get('predeal_min_margin', 15);
    }

    /**
     * Расчёт как в Excel: партнёр = %% от суммы; налог = ставка от суммы;
     * остаток = сумма − закуп − партнёр − доставка − комиссия − налог;
     * маржа = остаток / сумма × 100.
     */
    public static function calculate(array $d): array
    {
        $taxRate = ((float) Setting::get('tax_percent', 3)) / 100;
        $sum = (float) ($d['contract_sum'] ?? 0);
        $partner = round($sum * ((float) ($d['partner_pct'] ?? 0)) / 100, 2);
        $tax = round($sum * $taxRate, 2);
        $remainder = round($sum - (float) ($d['purchase_price'] ?? 0) - $partner
            - (float) ($d['delivery'] ?? 0) - (float) ($d['assembly'] ?? 0)
            - (float) ($d['commission'] ?? 0) - $tax, 2);

        return array_merge($d, [
            'partner_sum' => $partner,
            'tax' => $tax,
            'remainder' => $remainder,
            'margin' => $sum > 0 ? round($remainder / $sum * 100, 2) : 0,
        ]);
    }
}
