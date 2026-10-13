<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use App\Editorial\Contracts\EditorialDriver;
use App\Editorial\DriverFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The editorial carrier is chosen by config, built by a factory
        // that knows no provider's name. Swapping Anthropic for Vertex or
        // OpenAI is a class and a config block; nothing here changes.
        $this->app->singleton(DriverFactory::class);

        $this->app->bind(
            EditorialDriver::class,
            fn ($app) => $app->make(DriverFactory::class)->make(),
        );

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
