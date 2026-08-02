<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Deal;
use Illuminate\Support\Facades\DB;

class DealNumberService
{
    /**
     * Generate a unique deal number per company, e.g. BAIA-001 / ASU-001.
     * The prefix is the company code; the sequence runs per prefix.
     * Uses a row-locking transaction to avoid race conditions.
     */
    public function generate(?Company $company = null): string
    {
        $prefix = ($company?->code ?: 'BAIA').'-';

        return DB::transaction(function () use ($prefix) {
            // Максимум числового суффикса СТРОГОГО формата {CODE}-NNN — только по
            // ЖИВЫМ сделкам: удалённые счётчик не двигают (удалили все — нумерация
            // начнётся заново с 001). Легаси-номера (BAIA-2025-0042) игнорируются.
            // Парсим в PHP: sqlite (тесты) без REGEXP.
            $rows = Deal::withTrashed()
                ->where('number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->get(['number', 'deleted_at']);
            $suffix = fn ($n) => preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', (string) $n, $m) ? (int) $m[1] : 0;

            $next = (int) $rows->whereNull('deleted_at')->map(fn ($d) => $suffix($d->number))->max() + 1;

            // deals.number уникален ВКЛЮЧАЯ удалённые строки: занятые номера
            // (не переименованный легаси и т.п.) пропускаем, чтобы не упасть.
            $taken = $rows->map(fn ($d) => $suffix($d->number))->filter()->flip();
            while ($taken->has($next)) {
                $next++;
            }

            return $prefix.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
        });
    }
}
