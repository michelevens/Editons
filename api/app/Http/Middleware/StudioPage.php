<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A studio pane's data endpoint, opened in the browser's address bar.
 *
 * `/books/733/design` is the JSON the Design pane reads, and opened
 * directly (or reloaded) it showed that JSON as a page (ADR — Author
 * walkthrough fixes). A browser navigation is sent to the studio with the
 * pane open instead; the pane's own fetch is unchanged.
 *
 * Usage: ->middleware(StudioPage::class.':design')
 */
class StudioPage
{
    public function handle(Request $request, Closure $next, string $pane = ''): Response
    {
        $inertia = (bool) $request->header('X-Inertia');

        if (! $request->isMethod('GET') || ! ($inertia || self::isNavigation($request)) || ! ($book = $request->route('book'))) {
            return $next($request);
        }

        // Only a pane that would have answered with its data: a 403, a 404
        // (a module switched off) or a redirect stays what it was.
        $response = $next($request);

        if (! $response instanceof JsonResponse || ! $response->isSuccessful()) {
            return $response;
        }

        $id = is_object($book) ? $book->getKey() : $book;
        $url = $pane === 'guided'
            ? route('journey.guided', $id)
            : route('books.edit', array_filter(['book' => $id, 'pane' => $pane ?: null]));

        // An Inertia link to the pane's URL (Book Settings' "Open Design")
        // got JSON where it expected a page; it is sent there in full.
        return $inertia ? Inertia::location($url) : redirect()->to($url);
    }

    /** A page load in the browser, not the studio's own fetch or an Inertia visit. */
    public static function isNavigation(Request $request): bool
    {
        if ($request->header('X-Inertia') || $request->ajax() || $request->wantsJson()) {
            return false;
        }

        return $request->header('Sec-Fetch-Mode') === 'navigate'
            || str_contains((string) $request->header('Accept'), 'text/html');
    }
}
