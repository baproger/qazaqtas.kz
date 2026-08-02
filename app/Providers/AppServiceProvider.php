<?php

namespace App\Providers;

use App\Models\Deal;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
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
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        Vite::prefetch(concurrency: 3);

        // Admins bypass all policy/permission checks.
        Gate::before(fn (User $user, string $ability) => $user->hasRole('admin') ? true : null);

        // Stable polymorphic aliases used across tasks/documents/comments/etc.
        Relation::enforceMorphMap([
            'deal' => Deal::class,
            'project' => Project::class,
            'user' => User::class,
        ]);
    }
}
