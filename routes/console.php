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
// Сегодня истекает срок КП по заявке → уведомление её менеджеру.
Schedule::command('pre-deals:notify-quote-deadline')->dailyAt('09:00');
