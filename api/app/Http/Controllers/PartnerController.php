<?php

namespace App\Http\Controllers;

use App\Commerce\Partners\Applications;
use App\Commerce\Partners\Handles;
use App\Commerce\Partners\Insights;
use App\Commerce\Partners\Invitations;
use App\Commerce\Partners\Ledger;
use App\Commerce\Partners\PartnerPages;
use App\Commerce\Partners\Program;
use App\Models\Partner;
use App\Models\Payout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Écrivain Partners, for the partner: applying, the dashboard, the page
 * editor, payout details, and accepting an author's invitation.
 *
 * Every route is behind `auth` and `feature:partners`, and only ever works
 * on the signed-in account's own partner record: there is no parameter
 * naming a partner. Payout details and the agreement are refused while
 * "View as" runs (App\Platform\Impersonation).
 */
class PartnerController extends Controller
{
    public function apply(Request $request): Response|RedirectResponse
    {
        $partner = PartnerPages::of($request->user());

        if ($partner && $partner->status !== 'declined') {
            return redirect()->route('partners.dashboard');
        }

        return Inertia::render('Partners/Apply', [
            'declined' => $partner ? ['reason' => $partner->decline_reason, 'at' => $partner->declined_at?->toIso8601String()] : null,
            'previous' => $partner ? [
                'display_name' => $partner->display_name,
                'country' => $partner->country,
                'promotion_type' => $partner->promotion_type,
                'promotion_note' => $partner->promotion_note,
                'links' => $partner->links ?? [],
            ] : null,
            'promotion' => collect(Partner::PROMOTION)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'agreement' => self::agreement(),
            'rates' => app(Program::class)->rates(),
            'hold_days' => app(Program::class)->get('hold_days'),
        ]);
    }

    public function storeApplication(Request $request, Applications $applications): RedirectResponse
    {
        $data = $request->validate([
            'display_name' => ['required', 'string', 'min:2', 'max:80'],
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'promotion_type' => ['required', Rule::in(array_keys(Partner::PROMOTION))],
            'promotion_note' => ['nullable', 'string', 'max:1000'],
            'links' => ['array', 'max:3'],
            'links.*' => ['nullable', 'string', 'max:300'],
            'agreement_version' => ['required', 'string'],
            'accept' => ['accepted'],
        ]);

        $links = array_values(array_filter(array_map('trim', $data['links'] ?? []), fn ($l) => $l !== ''));

        foreach ($links as $i => $link) {
            if (! PartnerPages::isHttps($link)) {
                return back()->withErrors(["links.{$i}" => 'Links must start with https://.'])->withInput();
            }
        }

        $applications->apply($request->user(), [
            'display_name' => $data['display_name'],
            'country' => $data['country'] ?? null,
            'promotion_type' => $data['promotion_type'],
            'promotion_note' => $data['promotion_note'] ?? null,
            'links' => $links,
        ], $data['agreement_version']);

        return redirect()->route('partners.dashboard')->with('platform_notice', 'Thank you. Your application is in the queue.');
    }

    public function dashboard(Request $request, Ledger $ledger, PartnerPages $pages, Program $program): Response|RedirectResponse
    {
        $partner = PartnerPages::of($request->user());

        if (! $partner) {
            return redirect()->route('partners.apply');
        }

        $tab = in_array($request->query('tab'), ['overview', 'links', 'page', 'commissions', 'payouts', 'agreement'], true)
            ? $request->query('tab')
            : 'overview';

        $balances = array_values($ledger->balances($partner));

        return Inertia::render('Partners/Dashboard', [
            'tab' => $tab,
            'partner' => [
                'status' => $partner->status,
                'code' => $partner->code,
                'handle' => $partner->handle,
                'handle_changed_at' => $partner->handle_changed_at?->toIso8601String(),
                'handle_change_from' => $partner->handle_changed_at?->copy()->addDays((int) config('partners.handle_change_days'))->toIso8601String(),
                'display_name' => $partner->display_name,
                'tagline' => $partner->tagline,
                'bio' => $partner->bio,
                'accent' => $partner->accent,
                'page_locale' => $partner->page_locale,
                'socials' => $partner->socials ?? [],
                'restricted' => $partner->restricted,
                'suspend_reason' => $partner->isSuspended() ? $partner->suspend_reason : null,
                'url' => $partner->pageUrl(),
                'agreement_version' => $partner->agreement_version,
                'agreement_accepted_at' => $partner->agreement_accepted_at?->toIso8601String(),
                'payout_method' => $partner->payout_method,
                // The partner's own details, shown back to them only.
                'payout_details' => $partner->payout_details,
                'payout_details_updated_at' => $partner->payout_details_updated_at?->toIso8601String(),
            ],
            'page' => $pages->present($partner),
            'shelf' => $partner->shelf()->with('product')->get()->filter(fn ($i) => $i->product)->map(fn ($i) => ['product' => $i->product->slug, 'note' => $i->note])->values(),
            'catalogue' => $pages->catalogue($partner),
            'stats' => Insights::stats($partner),
            'timeline' => Insights::timeline($partner),
            'balances' => $balances,
            'eligibility' => collect($balances)->mapWithKeys(fn (array $b) => [$b['currency'] => $ledger->eligibility($partner, $b['currency'])]),
            'commissions' => Insights::commissions($partner),
            'payouts' => $ledger->payouts($partner)->map(fn (Payout $p) => [
                'id' => $p->id,
                'amount_cents' => $p->amount_cents,
                'currency' => $p->currency,
                'method' => Payout::METHODS[$p->method] ?? $p->method,
                'reference' => $p->reference,
                'paid_on' => $p->paid_on->toDateString(),
            ])->values(),
            'payout_methods' => collect(Partner::PAYOUT_METHODS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'minimum_cents' => $program->get('payout_minimum_cents'),
            'hold_days' => $program->get('hold_days'),
            'cookie_days' => $program->get('cookie_days'),
            'shelf_max' => (int) config('partners.shelf_max'),
            'locales' => Partner::LOCALES,
            'social_kinds' => PartnerPages::SOCIAL_KINDS,
            'agreement' => self::agreement(),
        ]);
    }

    public function updatePage(Request $request, PartnerPages $pages): RedirectResponse
    {
        $partner = $this->own($request);

        $data = $request->validate([
            'handle' => ['required', 'string', 'max:30'],
            'display_name' => ['required', 'string', 'min:2', 'max:80'],
            'tagline' => ['nullable', 'string', 'max:80'],
            'bio' => ['nullable', 'string', 'max:600'],
            'accent' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'page_locale' => ['required', Rule::in(Partner::LOCALES)],
            'socials' => ['array', 'max:6'],
            'socials.*.kind' => ['required', Rule::in(PartnerPages::SOCIAL_KINDS)],
            'socials.*.value' => ['nullable', 'string', 'max:300'],
            'shelf' => ['array', 'max:'.(int) config('partners.shelf_max')],
            'shelf.*.product' => ['required', 'string', 'max:120', 'distinct'],
            'shelf.*.note' => ['nullable', 'string', 'max:200'],
        ]);

        $pages->update($partner, $data + ['shelf' => $data['shelf'] ?? []]);

        return back()->with('platform_notice', 'Your page is saved.');
    }

    public function checkHandle(Request $request): JsonResponse
    {
        $partner = $this->own($request);
        $handle = strtolower(trim((string) $request->query('handle')));

        return response()->json(['handle' => $handle, 'problem' => $handle === $partner->handle ? null : Handles::problem($handle, $partner)]);
    }

    public function uploadLogo(Request $request, PartnerPages $pages): RedirectResponse
    {
        $partner = $this->own($request);

        $request->validate([
            // Explicit types: `image` would accept SVG, which can carry script.
            'logo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $pages->uploadLogo($partner, $request->file('logo'));

        return back()->with('platform_notice', 'Logo updated.');
    }

    public function removeLogo(Request $request, PartnerPages $pages): RedirectResponse
    {
        $pages->removeLogo($this->own($request));

        return back()->with('platform_notice', 'Logo removed.');
    }

    public function updatePayoutDetails(Request $request): RedirectResponse
    {
        $partner = $this->own($request);

        $data = $request->validate([
            'payout_method' => ['required', Rule::in(array_keys(Partner::PAYOUT_METHODS))],
            'payout_details' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($data['payout_method'] === 'paypal' && ! filter_var(trim($data['payout_details']), FILTER_VALIDATE_EMAIL)) {
            return back()->withErrors(['payout_details' => 'Enter the email address of your PayPal account.']);
        }

        if ($data['payout_method'] === 'moncash' && ! preg_match('/^\+?[0-9 ().-]{7,20}$/', trim($data['payout_details']))) {
            return back()->withErrors(['payout_details' => 'Enter the phone number of your MonCash account.']);
        }

        $partner->forceFill([
            'payout_method' => $data['payout_method'],
            'payout_details' => trim(strip_tags($data['payout_details'])),
            'payout_details_updated_at' => now(),
        ])->save();

        return back()->with('platform_notice', 'Payout details saved.');
    }

    // ── An author's invitation ──────────────────────────────────────────

    public function invitation(Request $request, string $token, Invitations $invitations): Response
    {
        $invitation = $invitations->find($token);
        abort_unless($invitation, 404);

        [$name, $titles] = Invitations::presentation($invitation->seller);

        return Inertia::render('Partners/Invitation', [
            'token' => $token,
            'open' => $invitation->isOpen(),
            'seller' => $name,
            'titles' => $titles,
            'email' => $invitation->email,
            'signed_in_as' => $request->user()?->email,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'agreement' => self::agreement(),
        ]);
    }

    public function acceptInvitation(Request $request, string $token, Invitations $invitations): RedirectResponse
    {
        $invitation = $invitations->find($token);
        abort_unless($invitation, 404);

        $data = $request->validate(['agreement_version' => ['required', 'string'], 'accept' => ['accepted']]);

        $invitations->accept($invitation, $request->user(), $data['agreement_version']);

        return redirect()->route('partners.dashboard', ['tab' => 'page'])->with('platform_notice', 'You are a partner for these books. Set up your page.');
    }

    // ── Internals ───────────────────────────────────────────────────────

    private function own(Request $request): Partner
    {
        $partner = PartnerPages::of($request->user());
        abort_unless($partner, 404);

        return $partner;
    }

    /** The agreement and disclosure, versioned (resources/views/partners). @return array{version: string, text: string, draft: bool} */
    public static function agreement(): array
    {
        return [
            'version' => (string) config('partners.agreement_version'),
            'text' => view('partners.agreement', [
                'rate' => app(Program::class)->rates(),
                'hold' => app(Program::class)->get('hold_days'),
                'cookie' => app(Program::class)->get('cookie_days'),
                'minimum' => number_format(app(Program::class)->get('payout_minimum_cents') / 100, 2),
            ])->render(),
            'draft' => str_contains((string) config('partners.agreement_version'), 'draft'),
        ];
    }
}
