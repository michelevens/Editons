<?php

namespace App\Providers;

use App\Commerce\Gateway\FakeGateway;
use App\Commerce\Gateway\PaymentGateway;
use App\Commerce\Gateway\StripeGateway;
use App\Editorial\Contracts\EditorialDriver;
use App\Editorial\DriverFactory;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Middleware\EnsureAccountActive;
use App\Models\User;
use App\Platform\Ai\AiControls;
use App\Platform\Features;
use App\Platform\Ops\Heartbeats;
use App\Platform\Ops\MailLogger;
use App\Platform\PlatformSettings;
use App\Platform\TwoFactor;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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

        // Platform settings memoise per container, so a test or a queue job
        // never reads another's values from a static cache.
        $this->app->singleton(PlatformSettings::class);
        $this->app->singleton(TwoFactor::class);
        // Likewise the AI kill switches and the feature flags.
        $this->app->singleton(AiControls::class);
        $this->app->singleton(Features::class);

        // feature('storefront', $user). A plain function file rather than a
        // composer "files" entry, so adding it needs no autoload rebuild.
        require_once app_path('Platform/helpers.php');

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
        $this->configurePlatform();

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
     * The admin console: who may do what, how often, and the session's
     * sign-in time that "sign out everywhere" is compared with.
     */
    private function configurePlatform(): void
    {
        // Every sign-in — password, two-factor, registration, remember-me,
        // "View as" and its return — stamps the session (EnsureAccountActive).
        Event::listen(Login::class, function () {
            if (app()->bound('request') && request()->hasSession()) {
                request()->session()->put(EnsureAccountActive::SIGNED_IN_AT, now()->getTimestamp());
            }
        });

        // Owner-only console actions: plans, deductions, suspension, 2FA
        // resets, roles and settings. Support reads, takes notes, adds small
        // credit top-ups and can "View as".
        Gate::define('platform.owner', fn (User $user) => $user->isPlatformOwner());

        // Dangerous console actions, per admin: enough for a busy support
        // shift, too few for a script working through the user table.
        RateLimiter::for('admin-actions', fn (Request $request) => [
            Limit::perMinute(20)->by('admin-minute|'.$request->user()?->id),
            Limit::perHour(200)->by('admin-hour|'.$request->user()?->id),
        ]);
        // Sending invitations, per staff member: a list is one request, so
        // the daily cap (Invitations::remainingToday) does the counting.
        RateLimiter::for('invitations', fn (Request $request) => [
            Limit::perMinute(10)->by('invitations-minute|'.$request->user()?->id),
            Limit::perHour(60)->by('invitations-hour|'.$request->user()?->id),
        ]);
        RateLimiter::for('impersonation', fn (Request $request) => Limit::perMinute(5)->by('view-as|'.$request->user()?->id));

        // Running the platform (phase 2): heartbeats from every scheduled
        // command and the worker, and the email log.
        Heartbeats::listen();
        MailLogger::listen();

        // Support requests: a person writing for help, not a script.
        RateLimiter::for('support', fn (Request $request) => [
            Limit::perMinute(5)->by('support-minute|'.$request->user()?->id),
            Limit::perHour(30)->by('support-hour|'.$request->user()?->id),
        ]);

        // "Report this book" from the public storefront: a reader, not a
        // campaign. Per IP, since nobody needs an account to report.
        RateLimiter::for('content-reports', fn (Request $request) => [
            Limit::perMinute(3)->by('reports-minute|'.$request->ip()),
            Limit::perHour(10)->by('reports-hour|'.$request->ip()),
        ]);

        // Browser error reports: a page in a crash loop must not fill the
        // table, so per person (or IP when signed out) and per IP.
        RateLimiter::for('client-errors', fn (Request $request) => [
            Limit::perMinute((int) config('platform.errors.client_per_minute', 10))->by('client-errors|'.($request->user()?->id ?? $request->ip())),
            Limit::perMinute(60)->by('client-errors-ip|'.$request->ip()),
        ]);

        // Six digits are a million guesses; five a minute per pending
        // sign-in (and per IP) makes them take longer than the code lives.
        RateLimiter::for('two-factor', fn (Request $request) => [
            Limit::perMinute(5)->by('2fa|'.$request->session()->get('login.id').'|'.$request->ip()),
            Limit::perHour(30)->by('2fa-hour|'.$request->ip()),
        ]);
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
