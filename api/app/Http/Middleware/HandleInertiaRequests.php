<?php

namespace App\Http\Middleware;

use App\Billing\Entitlements;
use App\Editorial\DriverFactory;
use App\Http\Controllers\InboxController;
use App\I18n\Locales;
use App\Platform\Features;
use App\Platform\Impersonation;
use App\Platform\PlatformSettings;
use App\Platform\Trust\StatusPage;
use App\Workspace\Preferences;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),

                /*
                 * Plan and credits for the account menu.
                 *
                 * summaryFor() has carried a `credits_pct` field since it was
                 * written, commented "for the little bar in the account
                 * menu" — a menu that did not exist. The avatar in the studio
                 * header was a <span>, so there was no way to reach billing,
                 * the profile or even sign out without typing a URL.
                 *
                 * Lazily evaluated: this runs on every Inertia response, and
                 * summaryFor() grants the monthly allowance and sums the
                 * ledger. Closures are resolved only when the page actually
                 * asks for the prop.
                 */
                'account' => $request->user()
                    ? fn () => array_merge(
                        app(Entitlements::class)->summaryFor($request->user()),
                        // Counted here rather than in summaryFor(), which the
                        // credit strip and every review response also use: a
                        // count added there would run on each of them to
                        // serve a line only this menu shows.
                        ['books_active' => $request->user()->books()
                            ->where('status', '!=', 'archived')
                            ->count()],
                    )
                    : null,
            ],

            // The book a page without one should open: the header's areas
            // (AI Editor, Design...) are book screens, and on the shelf,
            // billing or the profile they used to go nowhere. Lazy, like the
            // account summary.
            'recent_book_id' => $request->user()
                ? fn () => $request->user()->books()
                    ->where('status', '!=', 'archived')
                    ->latest('updated_at')
                    ->value('id')
                : null,

            // Unread items in the account inbox, for the badge on the tab
            // bar and the dot on the avatar. Lazy, like the account summary.
            'inbox_unread' => $request->user()
                ? fn () => InboxController::unread($request->user())
                : 0,

            /*
             | The platform layer: the "View as" banner, the announcement,
             | and whether this account may open the admin console (the link
             | in the account menu). A "View as" session is never staff, so
             | the console link disappears while viewing.
             */
            'platform' => fn () => [
                'role' => $request->user()?->isPlatformAdmin() && ! Impersonation::active($request)
                    ? $request->user()->platform_role
                    : null,
                'impersonation' => Impersonation::banner($request),
                // The owner's announcement; failing that, an open incident
                // posted with "show in the banner" (App\Platform\Trust\StatusPage).
                'announcement' => app(PlatformSettings::class)->announcementFor(
                    Impersonation::active($request) ? null : $request->user(),
                ) ?? StatusPage::banner(),
                'maintenance' => $request->user()?->isPlatformAdmin()
                    ? app(PlatformSettings::class)->maintenance()
                    : false,
                'staff_name' => config('platform.staff_name'),
            ],

            // The author's own shortcuts, phone tabs and review-deck swipes
            // (App\Workspace\Preferences): only what they changed. Every
            // page needs them: shortcuts and the tab bar are everywhere.
            'prefs' => fn () => $request->user() ? Preferences::for($request->user()) : null,

            // Modules switched on for this person (App\Platform\Features): a
            // module that is off disappears from the sidebar, the More sheet
            // and the account menu. During View as, the viewed account's.
            'features' => fn () => app(Features::class)->resolve($request->user()),

            // One-off messages from a redirect: "Viewing ended", a console
            // action's result. Read once, like any flash.
            'flash' => fn () => [
                'notice' => $request->session()->get('platform_notice'),
                'error' => $request->session()->get('platform_error'),
            ],

            // Demo credentials, shown on the login page.
            //
            // This publishes a working password to anyone who loads the page,
            // so it is never implicit: it appears only where DEMO_EMAIL and
            // DEMO_PASSWORD are both set, or on a developer machine with the
            // seeded account. Turning it off in production is unsetting a
            // variable, not editing code.
            //
            // The account it names must be treated as public. Keep it on its
            // own books, and never promote it or let it hold anything real.
            'demo' => $this->demoCredentials(),

            /*
             | The interface language (App\I18n\Locales, set by SetLocale).
             | The strings themselves are a once prop: sent on the first
             | visit and again only when the language or the catalogue
             | changes, not with every page. English sends none (the bundle
             | has en.json, which is also every other language's fallback).
             */
            'i18n' => fn () => [
                'locale' => app()->getLocale(),
                'intl' => Locales::intl(app()->getLocale()),
                'locales' => collect(Locales::NAMES)
                    ->map(fn ($name, $code) => ['code' => $code, 'name' => $name])
                    ->values(),
            ],
            'i18n_messages' => Inertia::once(fn () => (object) Locales::messagesFor(app()->getLocale()))
                ->as('i18n:'.app()->getLocale().':'.Locales::version(app()->getLocale())),

            // Whether editorial reviews here are sample output (heuristics,
            // no model). The studio labels findings and the credit strip
            // accordingly; nothing is charged for them.
            'editorial' => ['simulated' => DriverFactory::simulated()],
        ];
    }

    /**
     * The credentials to offer on the login page, if any.
     *
     * @return array{email: string, password: string}|null
     */
    private function demoCredentials(): ?array
    {
        $email = config('app.demo_email');
        $password = config('app.demo_password');

        if ($email && $password) {
            return ['email' => $email, 'password' => $password];
        }

        // Local fallback so a fresh clone can sign in without configuring
        // anything. Deliberately not a fallback anywhere else.
        if (app()->environment('local')) {
            return ['email' => 'test@editons.local', 'password' => 'password'];
        }

        return null;
    }
}
