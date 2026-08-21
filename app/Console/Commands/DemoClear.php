<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

/** Убрать демо-данные: пользователей @demo.kz, сделки DEMO-* и демо-бригаду. */
class DemoClear extends Command
{
    protected $signature = 'demo:clear';

    protected $description = 'Удалить демонстрационные данные (пользователи @demo.kz, сделки DEMO-*, демо-бригады)';

    public function handle(): int
    {
        app(DemoSeeder::class)->clear();
        $this->info('Демо-данные удалены.');

        return self::SUCCESS;
    }
}
