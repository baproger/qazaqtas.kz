<?php

namespace App\Console\Commands;

use App\Models\PreDeal;
use App\Notifications\QuoteDeadlineToday;
use Illuminate\Console\Command;

/**
 * Заявки, у которых СЕГОДНЯ истекает срок действия КП: напоминание
 * ответственному менеджеру. Раз в день по расписанию (09:00) — по одному разу
 * на заявку; уже переведённые в сделку не напоминаются.
 */
class NotifyQuoteDeadlines extends Command
{
    protected $signature = 'pre-deals:notify-quote-deadline';

    protected $description = 'Notify managers whose quote (КП) expires today.';

    public function handle(): int
    {
        $requests = PreDeal::whereDate('valid_until', now()->toDateString())
            ->where('status', 'new')
            ->with('user')->get();

        foreach ($requests as $request) {
            $request->user?->notify(new QuoteDeadlineToday($request));
        }

        $this->info("Notified: {$requests->count()} request(s).");

        return self::SUCCESS;
    }
}
