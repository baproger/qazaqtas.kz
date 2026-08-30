<?php

namespace App\Providers;

use App\Models\Deal;
use App\Models\DealItem;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkOrder;
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
        Relation::enforceMorphMap([
            'deal' => Deal::class,
            'deal_item' => DealItem::class,
            'project' => Project::class,
            // Наряд — источник движения склада: подтверждённая выработка.
            'work_order' => WorkOrder::class,
            'user' => User::class,
            'task' => Task::class,
        ]);
    }
}
