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

            // Demo credentials, shown on the login page while the product is
            // under construction.
            //
            // Gated on APP_ENV=local and nothing else. A seeded password in
            // page props is harmless on a developer machine and a handed-out
            // account in production; the environment check is the whole
            // safeguard, so it is deliberately the first condition and not
            // buried behind a config flag someone can flip by accident.
            'demo' => app()->environment('local') ? [
                'email' => 'test@editons.local',
                'password' => 'password',
            ] : null,
        ];
    }
}
