<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keep generated URLs under /rent247 when served via XAMPP subdirectory.
        // Skip in tests/CLI so PHPUnit routes stay at the default app root.
        if ($this->app->runningInConsole()) {
            return;
        }

        $root = rtrim((string) config('app.url'), '/');
        if ($root !== '' && str_contains($root, '/rent247')) {
            URL::forceRootUrl($root);
        }
    }
}
