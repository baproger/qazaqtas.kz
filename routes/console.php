<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use Illuminate\Support\Facades\Schedule;

Schedule::command('tasks:notify-overdue')->hourly();
// Дни рождения: раз в день утром, чтобы «сегодня»/«через 3 дня» не дублировались.
Schedule::command('users:notify-birthdays')->dailyAt('09:00');
// Долги сотрудников: удержание из бонуса за ПРОШЛЫЙ месяц, 1-го числа.
// К этому моменту месяц закрыт и бонус по нему уже не изменится.
Schedule::command('debts:charge')->monthlyOn(1, '09:00');
// Заявка сотрудника, застрявшая в очереди бухгалтера, — это его деньги.
// Через три дня напоминаем бухгалтерии (однократно на заявку).
Schedule::command('expenses:notify-stale')->dailyAt('09:30');
