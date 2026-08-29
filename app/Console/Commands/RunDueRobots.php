<?php

namespace App\Console\Commands;

use App\Robots\Runner;
use Illuminate\Console\Command;

class RunDueRobots extends Command
{
    protected $signature = 'robots:run-due';

    protected $description = 'Выполнить отложенные и цепочные запуски роботов этапов, чьё время пришло';

    public function handle(Runner $runner): int
    {
        $n = $runner->runDue();
        $this->info("Выполнено запусков: {$n}");

        return self::SUCCESS;
    }
}
