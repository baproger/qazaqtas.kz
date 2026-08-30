<?php

namespace App\Providers;

use App\Models\BonusPayout;
use App\Models\CashReceipt;
use App\Models\Deal;
use App\Models\DealItem;
use App\Models\DealStage;
use App\Models\Debt;
use App\Models\EmployeeDebt;
use App\Models\EmployeeDebtPayment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PayrollAdjustment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Setting;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkHour;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Support\ReportCache;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Прод за HTTPS: генерим только https-ссылки (mixed content / редиректы).
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        Vite::prefetch(concurrency: 3);

        // Admins bypass all policy/permission checks.
        Gate::before(fn (User $user, string $ability) => $user->hasRole('admin') ? true : null);

        // Stable polymorphic aliases used across tasks/documents/comments/etc.
        // Деньги изменились → отчёты пересчитать (Support\ReportCache).
        foreach ([Deal::class, DealItem::class, Invoice::class, Payment::class, Expense::class,
            WorkOrder::class, WorkOrderLine::class, BonusPayout::class, PayrollAdjustment::class,
            EmployeeDebt::class, EmployeeDebtPayment::class, WorkHour::class, Setting::class,
            DealStage::class, Debt::class, CashReceipt::class, User::class] as $model) {
            $model::saved(fn () => ReportCache::bump());
            $model::deleted(fn () => ReportCache::bump());
        }

        Relation::enforceMorphMap([
            'deal' => Deal::class,
            'deal_item' => DealItem::class,
            'project' => Project::class,
            // Наряд — источник движения склада: подтверждённая выработка.
            'work_order' => WorkOrder::class,
            'user' => User::class,
            'task' => Task::class,
            'service' => Service::class,
            'service_category' => ServiceCategory::class,
        ]);
    }
}
