<?php

namespace App\Http\Middleware;

use App\I18n\Locales;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the request in the reader's interface language (App\I18n\Locales).
 *
 * An account that has never had one gets the browser's on its first visit,
 * stored, so the language stays put on another device; after that only the
 * switcher changes it. Validation messages, notices, mail sent from this
 * request and `<html lang>` all follow.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = Locales::forRequest($request);
        $user = $request->user();

        if ($user instanceof User && $user->locale === null) {
            $user->forceFill(['locale' => $locale])->saveQuietly();
        }

        app()->setLocale($locale);
        Carbon::setLocale($locale);

        return $next($request);
    }
}
