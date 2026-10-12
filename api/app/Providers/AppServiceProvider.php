<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use App\Editorial\Contracts\EditorialDriver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind the editorial driver from config. This one line is the whole
        // provider-agnostic gateway ARCHITECTURE.md asks for: swapping to
        // Anthropic means a new class and a config value, not a refactor.
        $this->app->bind(EditorialDriver::class, function ($app) {
            $driver = config('editorial.driver');
            $class = config("editorial.drivers.{$driver}")
                ?? throw new \InvalidArgumentException("Unknown editorial driver [{$driver}].");

            return $app->make($class);
        });

        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
