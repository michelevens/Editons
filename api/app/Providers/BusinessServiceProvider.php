<?php

namespace App\Providers;

use App\Billing\PlanCatalog;
use App\Billing\SubscriptionSync;
use App\Commerce\Partners\Program;
use App\Http\Middleware\CaptureAttribution;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * The console's business pages (ADR — console business): the plan
 * catalogue, the subscription state Cashier does not keep, and first-touch
 * attribution. A provider of its own so the wiring sits in one place.
 */
class BusinessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: loaded once per request or queued job, so a long-running
        // worker never keeps a catalogue the owner has since changed.
        $this->app->scoped(PlanCatalog::class);
        // Écrivain Partners' settings, read once per request or job.
        $this->app->scoped(Program::class);
    }

    public function boot(Router $router): void
    {
        // After Cashier has handled the event, like SubscriptionEmails.
        // Registered here, not discovered: discovery would add it twice.
        Event::listen(WebhookHandled::class, [SubscriptionSync::class, 'handle']);

        // Where a visitor first came from, for the acquisition report.
        $router->pushMiddlewareToGroup('web', CaptureAttribution::class);
    }
}
