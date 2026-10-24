<?php

namespace App\Http\Controllers;

use App\Commerce\Partners\Attribution;
use App\Commerce\Partners\Handles;
use App\Commerce\Partners\PartnerPages;
use App\Commerce\Partners\Program;
use App\Models\ContentReport;
use App\Models\Partner;
use App\Models\Product;
use App\Platform\Trust\Moderation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * A partner's public page, /p/{handle}, and the links that lead from it.
 *
 * Public: no sign-in to look or to buy. Only an approved partner's page is
 * live. Before approval the partner (and staff) can preview it, marked
 * `noindex`; anybody else, and everybody while the partner is suspended,
 * gets a neutral "not available" page, also `noindex`, which says nothing
 * about why. A handle the partner has moved away from redirects (301) to
 * the new one, so printed QR codes keep working.
 *
 * Every visit through the page or a book link gives the partner the credit
 * for a later purchase (App\Commerce\Partners\Attribution).
 *
 * 404 throughout while the program is off.
 */
class PartnerPageController extends Controller
{
    public function show(Request $request, string $handle, PartnerPages $pages, Attribution $attribution): Response
    {
        [$partner, $moved] = $this->find($handle);

        if ($moved) {
            return redirect()->route('partners.page', array_filter([$partner->handle, 'via' => $request->query('via')]), 301);
        }

        $preview = $this->canPreview($request, $partner);

        if (! $partner->isApproved() && ! $preview) {
            return $this->unavailable();
        }

        if ($partner->isApproved()) {
            $attribution->click($request, $partner, $request->query('via') === 'qr' ? 'qr' : 'page');
        }

        $page = $pages->present($partner);
        $live = $partner->isApproved();

        $response = Inertia::render('Partners/Page', [
            'page' => $page,
            'preview' => $preview && ! $live,
            'live' => $live,
        ])->withViewData(['meta' => [
            'title' => $partner->display_name.' · Écrivain Partner',
            'description' => $partner->tagline ?: PartnerPages::disclosure($partner),
            'url' => $page['url'],
            'image' => $page['logo_url'],
            'noindex' => ! $live,
        ]])->toResponse($request);

        if (! $live) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    /** A book link: the credit, then the book's own store page. */
    public function book(Request $request, string $handle, string $slug, Attribution $attribution): RedirectResponse|Response
    {
        [$partner, $moved] = $this->find($handle);
        $product = Product::where('slug', $slug)->firstOrFail();

        if ($moved) {
            return redirect()->route('partners.page.book', [$partner->handle, $product->slug], 301);
        }

        if ($partner->isApproved()) {
            $attribution->click($request, $partner, $request->query('via') === 'qr' ? 'qr' : 'link', $product);
        }

        return redirect()->route('store.product', $product->slug);
    }

    public function qr(Request $request, string $handle, string $format): Response
    {
        [$partner] = $this->find($handle);
        abort_unless($partner->isApproved() || $this->canPreview($request, $partner), 404);

        // ?book=slug: the code of one book's link, for a flyer about it.
        $book = $request->filled('book') ? Product::where('slug', (string) $request->query('book'))->first() : null;
        $url = $book ? route('partners.page.book', [$partner->handle, $book->slug]).'?via=qr' : null;

        return response(PartnerPages::qr($partner, $format, $url), 200, [
            'Content-Type' => $format === 'png' ? 'image/png' : 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="'.$partner->handle.($book ? '-'.$book->slug : '').'-qr.'.$format.'"',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function logo(Request $request, string $handle): Response
    {
        [$partner] = $this->find($handle);
        abort_unless($partner->logo_path && ($partner->isApproved() || $this->canPreview($request, $partner)), 404);
        abort_unless(Storage::disk('files')->exists($partner->logo_path), 404);

        return Storage::disk('files')->response($partner->logo_path, null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /** "Report this page": the console's moderation queue. */
    public function report(Request $request, string $handle, Moderation $moderation): RedirectResponse
    {
        [$partner] = $this->find($handle);
        abort_unless($partner->isApproved(), 404);

        $data = $request->validate([
            'category' => ['required', 'string', 'in:'.implode(',', array_keys(ContentReport::CATEGORIES))],
            'details' => ['nullable', 'string', 'max:2000'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'website' => ['nullable', 'max:0'],
        ]);

        $moderation->reportPartner($partner, ['category' => $data['category'], 'details' => $data['details'] ?? null, 'email' => $data['email'] ?? null], $request);

        return back()->with('platform_notice', 'Thank you. Écrivain will look at this page.');
    }

    // ── Internals ───────────────────────────────────────────────────────

    /** @return array{0: Partner, 1: bool} */
    private function find(string $handle): array
    {
        abort_unless(Program::enabled(), 404);

        [$partner, $moved] = Handles::resolve($handle);
        abort_unless($partner, 404);

        return [$partner->loadMissing('user'), $moved];
    }

    private function canPreview(Request $request, Partner $partner): bool
    {
        $user = $request->user();

        return $user && ($user->id === $partner->user_id || $user->isPlatformAdmin());
    }

    private function unavailable(): Response
    {
        return Inertia::render('Partners/Page', ['page' => null, 'preview' => false, 'live' => false])
            ->withViewData(['meta' => ['title' => 'Page not available · Écrivain', 'noindex' => true]])
            ->toResponse(request())
            ->setStatusCode(404)
            ->withHeaders(['X-Robots-Tag' => 'noindex, nofollow']);
    }
}
