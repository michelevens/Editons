<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        if (! $request->isMethod('GET') || ! self::isNavigation($request) || ! ($book = $request->route('book'))) {
            return $next($request);
        }

        $id = is_object($book) ? $book->getKey() : $book;

        if ($pane === 'guided') {
            return redirect()->route('journey.guided', $id);
        }

        return redirect()->route('books.edit', array_filter(['book' => $id, 'pane' => $pane ?: null]));
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
