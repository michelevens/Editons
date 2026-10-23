<?php

namespace App\Providers;

use App\Commerce\Gateway\FakeGateway;
use App\Commerce\Gateway\PaymentGateway;
use App\Commerce\Gateway\StripeGateway;
use App\Editorial\Contracts\EditorialDriver;
use App\Editorial\DriverFactory;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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

        // The storefront's payment provider. Stripe when keys are set, the
        // fake one (no network, test checkout page) otherwise; a singleton
        // so a test can read back the sessions it was asked for.
        $this->app->singleton(
            PaymentGateway::class,
            fn () => config('commerce.gateway') === 'stripe' ? new StripeGateway : new FakeGateway,
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

        $this->configureAuthRateLimits();

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

    /**
     * Per-IP limits on the doors that do not need an account.
     *
     * Sign-in already locks one address after five failures (LoginRequest),
     * but that is keyed on the email, so one password tried against many
     * accounts was never slowed. Registration and password resets had no
     * limit at all: every new account is granted free credits, and every
     * reset request is a mail sent on somebody else's behalf.
     *
     * Two windows each: a burst limit a person retrying will not reach, and
     * an hourly one a script will. The IP is the real client — see
     * App\Http\TrustedProxies for why that took more than request()->ip().
     */
    private function configureAuthRateLimits(): void
    {
        // Answer the way the form already shows errors — back, with a
        // message under the email field — rather than with a bare 429 page.
        $tooMany = function (Request $request, array $headers) {
            $seconds = (int) ($headers['Retry-After'] ?? 60);
            $message = "Too many attempts. Please try again in {$seconds} seconds.";

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 429, $headers);
            }

            return back()->withErrors(['email' => $message])->withHeaders($headers);
        };

        $limits = [
            'login' => [20, 200],
            'register' => [5, 30],
            'password-reset' => [5, 20],
        ];

        foreach ($limits as $name => [$perMinute, $perHour]) {
            RateLimiter::for($name, fn (Request $request) => [
                // Distinct keys: two limits sharing one would share a counter.
                Limit::perMinute($perMinute)->by('minute|'.$request->ip())->response($tooMany),
                Limit::perHour($perHour)->by('hour|'.$request->ip())->response($tooMany),
            ]);
        }
    }
}
