<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

class InvoiceNumberService
{
    public function generate(): string
    {
        $year = now()->year;
        $prefix = "INV-{$year}-";

        return DB::transaction(function () use ($prefix) {
            $last = Invoice::withTrashed()->where('number', 'like', $prefix.'%')
                ->lockForUpdate()->orderByDesc('id')->value('number');
            $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

            return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        });
    }
}
