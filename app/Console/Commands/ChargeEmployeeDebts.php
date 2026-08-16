<?php

namespace App\Console\Commands;

use App\Services\EmployeeDebtService;
use Illuminate\Console\Command;

/**
 * Месячное удержание долгов сотрудников из бонуса.
 *
 * Запускается 1-го числа за ПРОШЛЫЙ месяц: к этому моменту месяц закрыт и
 * бонус по нему уже не изменится. Пропущенный месяц догоняется вручную —
 * `php artisan debts:charge --month=2026-07`; повторный прогон безопасен.
 */
class ChargeEmployeeDebts extends Command
{
    protected $signature = 'debts:charge {--month= : Месяц YYYY-MM, по умолчанию прошлый}';

    protected $description = 'Удержать долги сотрудников из бонуса за месяц';

    public function handle(EmployeeDebtService $debts): int
    {
        $month = (string) ($this->option('month') ?: now()->subMonth()->format('Y-m'));

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $this->error('Месяц указывается как YYYY-MM, например --month=2026-07.');

            return self::FAILURE;
        }

        $result = $debts->chargeMonth($month);

        $this->info("Месяц {$month}: удержаний {$result['charged']} на {$result['sum']} ₸, закрыто долгов {$result['closed']}.");

        return self::SUCCESS;
    }
}
