<?php

namespace App\Http\Middleware;

use App\Platform\PlatformSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `platform.2fa`: staff without two-factor reach only its set-up page.
 *
 * On by default (require_2fa_for_admins). An admin console behind a
 * password alone is one phished password from every author's account.
 */
class RequirePlatformTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->hasTwoFactor() && app(PlatformSettings::class)->get('require_2fa_for_admins')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Turn on two-factor sign-in to use the admin console.'], 403);
            }

            return redirect()->route('admin.two-factor');
        }

        return $next($request);
    }
}
