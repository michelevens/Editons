<?php

namespace App\Providers;

use App\Editorial\Contracts\EditorialDriver;
use App\Editorial\DriverFactory;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Http\Controllers\WebhookController;

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

        // Cashier's route resolves its controller through the container,
        // so this swaps in the stricter one without redefining the route.
        $this->app->bind(
            WebhookController::class,
            StripeWebhookController::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Generate every URL from APP_URL rather than from the request host.
        //
        // Two proxies sit in front of this app — a Cloudflare Worker and
        // Railway's edge — and the second overwrites X-Forwarded-Host with
        // its own hostname. Laravel then built asset, Ziggy and redirect URLs
        // pointing at *.up.railway.app while the browser was on
        // app.ecrivain.ai. The page rendered with a 200, every generated URL
        // was cross-origin, and the React app never mounted: a blank screen
        // behind a success status, which is the worst way for this to fail.
        //
        // Pinning the root removes the dependency on a header surviving two
        // hops. APP_URL is already per-service and already correct.
        if ($url = config('app.url')) {
            URL::forceRootUrl($url);

            if (str_starts_with($url, 'https://')) {
                URL::forceHttps();
            }
        }
    }
}
