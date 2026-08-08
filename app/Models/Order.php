<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Заказ с сайта. В ERP попадает сразу, менеджер переводит его в сделку. */
class Order extends Model
{
    public const STATUSES = ['new' => 'Новый', 'in_work' => 'В работе', 'done' => 'Обработан', 'cancelled' => 'Отменён'];

    protected $fillable = [
        'number', 'company_id', 'name', 'phone', 'email', 'city', 'address',
        'delivery', 'comment', 'total', 'status', 'source', 'deal_id', 'manager_id',
    ];

    protected $casts = ['total' => 'decimal:2'];

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
