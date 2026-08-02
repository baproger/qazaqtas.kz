<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\BirthdayUpcoming;
use Illuminate\Console\Command;

/**
 * Дни рождения сотрудников: уведомляем руководство (admin/director/financist)
 * и руководителя отдела именинника. Запускается раз в день по расписанию —
 * «сегодня» и «через 3 дня» срабатывают по одному разу на каждую дату.
 */
class NotifyBirthdays extends Command
{
    protected $signature = 'users:notify-birthdays';

    protected $description = 'Notify leadership about employee birthdays (today and in 3 days).';

    public function handle(): int
    {
        $today = now()->format('m-d');
        $soon = now()->addDays(3)->format('m-d');

        $people = User::where('is_active', true)
            ->whereNotNull('birth_date')
            ->with('department:id,name,head_user_id')
            ->get()
            ->filter(fn ($u) => in_array($u->birth_date->format('m-d'), [$today, $soon], true));

        if ($people->isEmpty()) {
            $this->info('No birthdays today or in 3 days.');

            return self::SUCCESS;
        }

        $leadership = User::where('is_active', true)
            ->role(['admin', 'director', 'financist'])->get();

        $sent = 0;
        foreach ($people as $person) {
            $isToday = $person->birth_date->format('m-d') === $today;
            $recipients = $leadership->keyBy('id');
            // Руководитель отдела именинника — тоже в курсе.
            if (($headId = $person->department?->head_user_id) && $headId !== $person->id) {
                $head = User::where('is_active', true)->find($headId);
                if ($head) {
                    $recipients->put($head->id, $head);
                }
            }
            $recipients->forget($person->id); // самого именинника не уведомляем

            foreach ($recipients as $recipient) {
                $recipient->notify(new BirthdayUpcoming(
                    $person->id, $person->name, $person->birth_date->format('d.m'), $isToday
                ));
                $sent++;
            }
        }

        $this->info("Sent {$sent} birthday notification(s) for {$people->count()} employee(s).");

        return self::SUCCESS;
    }
}
