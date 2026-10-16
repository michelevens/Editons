<?php

namespace App\Http\Middleware;

use App\Billing\Entitlements;
use Illuminate\Http\Request;
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
