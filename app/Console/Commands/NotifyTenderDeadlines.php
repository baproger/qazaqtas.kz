<?php

namespace App\Console\Commands;

use App\Models\PreDeal;
use App\Notifications\TenderDeadlineToday;
use Illuminate\Console\Command;

/**
 * Лоты, у которых СЕГОДНЯ заканчивается тендер: уведомление ответственному
 * менеджеру лота. Раз в день по расписанию (09:00) — по одному разу на лот;
 * выигранные/отклонённые лоты не напоминаются.
 */
class NotifyTenderDeadlines extends Command
{
    protected $signature = 'pre-deals:notify-tender-deadline';

    protected $description = 'Notify lot managers whose tender ends today.';

    public function handle(): int
    {
        $lots = PreDeal::whereDate('tender_deadline', now()->toDateString())
            ->where('status', 'new')
            ->with('user')->get();

        foreach ($lots as $lot) {
            $lot->user?->notify(new TenderDeadlineToday($lot));
        }

        $this->info("Notified: {$lots->count()} lot(s).");

        return self::SUCCESS;
    }
}
