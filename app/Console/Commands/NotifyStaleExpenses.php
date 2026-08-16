<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Notifications\CompanyExpenseStale;
use App\Support\NotificationResolver;
use Illuminate\Console\Command;

/**
 * Напоминание о заявках, застрявших в очереди бухгалтера.
 *
 * Заявка, потерянная в очереди, — это сотрудник, заплативший из своего кармана
 * и ждущий возврата. Через три дня бухгалтерия получает напоминание со
 * ссылкой на своё рабочее место.
 *
 * Напоминаем ОДИН раз на заявку: ежедневный повтор быстро становится шумом,
 * который перестают читать. Отметка — на самой записи (`reminded_at`).
 */
class NotifyStaleExpenses extends Command
{
    protected $signature = 'expenses:notify-stale {--days=3 : сколько дней заявка ждёт}';

    protected $description = 'Напомнить бухгалтерии о заявках, ждущих проверки дольше N дней';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        $stale = Expense::where('status', 'pending')
            ->whereNull('expenseable_id')      // заявки компании: у расходов сделки есть задача
            ->whereNull('reminded_at')
            ->where('created_at', '<=', now()->subDays($days))
            ->with('responsible:id,name', 'category:id,name')
            ->get();

        if ($stale->isEmpty()) {
            $this->info('Залежавшихся заявок нет.');

            return self::SUCCESS;
        }

        $accountants = NotificationResolver::accountants();

        foreach ($stale as $expense) {
            foreach ($accountants as $accountant) {
                $accountant->notify(new CompanyExpenseStale($expense, $days));
            }
            $expense->forceFill(['reminded_at' => now()])->save();
        }

        $this->info('Напомнили о '.$stale->count().' заявке(ах) '.$accountants->count().' получателю(ям).');

        return self::SUCCESS;
    }
}
