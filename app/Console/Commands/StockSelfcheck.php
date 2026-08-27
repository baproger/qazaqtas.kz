<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\StockService;
use Illuminate\Console\Command;

/**
 * Сверка склада: сходится ли хранимый остаток с суммой движений.
 *
 * Остаток лежит числом в `product_stocks` ради скорости, а правда — в
 * `stock_movements`. Пишутся они одной транзакцией, но если когда-нибудь
 * разойдутся, узнать об этом надо от команды, а не от кладовщика, который
 * не может собрать паллету.
 *
 * `--fix` переписывает хранимый остаток суммой движений: движения — источник
 * правды, спорить с ними незачем.
 */
class StockSelfcheck extends Command
{
    protected $signature = 'stock:selfcheck {--fix : Переписать остаток суммой движений}';

    protected $description = 'Сверить остатки готовой продукции с движениями склада';

    public function handle(StockService $stock): int
    {
        $drift = $stock->drift();

        if ($drift->isEmpty()) {
            $this->info('Склад сходится: остатки равны сумме движений.');

            return self::SUCCESS;
        }

        $names = Product::whereIn('id', $drift->pluck('product_id'))->pluck('name', 'id');

        $this->error('Расхождений: '.$drift->count());
        $this->table(['Товар', 'Хранится', 'По движениям'], $drift->map(fn ($row) => [
            $names[$row->product_id] ?? ('#'.$row->product_id),
            $stock->qty((int) $row->product_id, $row->company_id ? (int) $row->company_id : null),
            round((float) $row->total, 2),
        ])->all());

        if (! $this->option('fix')) {
            $this->line('Починить: php artisan stock:selfcheck --fix');

            return self::FAILURE;
        }

        foreach ($drift as $row) {
            \App\Models\ProductStock::updateOrCreate(
                ['product_id' => $row->product_id, 'company_id' => $row->company_id],
                ['qty' => round((float) $row->total, 2)],
            );
        }

        $this->info('Остатки переписаны суммой движений.');

        return self::SUCCESS;
    }
}
