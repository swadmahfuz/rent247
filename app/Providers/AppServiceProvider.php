<?php

namespace App\Providers;

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
        // Intentionally no URL::forceRootUrl().
        // Under XAMPP (/rent247), Laravel already detects the base path from the
        // request (see public/index.php SCRIPT_NAME fix). Forcing APP_URL that
        // also contains /rent247 doubles paths like /rent247/rent247/dashboard.
    }
}
