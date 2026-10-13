<?php

namespace App\Http\Middleware;

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
