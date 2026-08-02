<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Строка ручной сводки ДДС на странице Финансы. Никаких связей со сделками,
 * счетами и платежами — финансист вводит цифры руками и видит их как ввёл.
 * kind: account — компания (банк/остаток/дебиторка); debt — долг (имя/сумма).
 */
class DdsEntry extends Model
{
    use \App\Models\Concerns\Auditable; // история: кто добавил/изменил/удалил строку ДДС

    protected $fillable = ['kind', 'name', 'bank', 'balance', 'receivable', 'amount', 'sort'];

    protected $casts = [
        'balance' => 'decimal:2',
        'receivable' => 'decimal:2',
        'amount' => 'decimal:2',
    ];
}
