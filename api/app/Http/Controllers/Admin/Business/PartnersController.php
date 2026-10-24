<?php

namespace App\Http\Controllers\Admin\Business;

use App\Commerce\Partners\Applications;
use App\Commerce\Partners\Insights;
use App\Commerce\Partners\Ledger;
use App\Commerce\Partners\PartnerPages;
use App\Commerce\Partners\Program;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Controller;
use App\Models\AdminNote;
use App\Models\DiscountCode;
use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\Payout;
use App\Platform\AdminAudit;
use App\Platform\Features;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Écrivain Partners in the console (Business → Partners).
 *
 * All staff read every tab and can approve or decline an application.
 * Everything that moves money or a partner's standing — suspend, reinstate,
 * release or forfeit a balance, record a payout, attach a discount code,
 * mark a tax form, open an invited partner to the whole catalogue — and the
 * program's settings are the owner's (routes/admin-business.php). Every one
 * needs a reason and is audited in the same transaction.
 */
class PartnersController extends Controller
{
    private const TABS = ['applications', 'partners', 'commissions', 'payouts', 'settings'];

    public function index(Request $request, Ledger $ledger, Program $program): Response
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'applications';
        $filters = $this->filters($request);

        return Inertia::render('Admin/Business/Partners', [
            'tab' => $tab,
            'enabled' => Features::enabled('partners'),
            'counts' => [
                'applications' => Partner::where('status', 'applied')->count(),
                'partners' => Partner::whereIn('status', ['approved', 'suspended'])->count(),
            ],
            'applications' => $tab === 'applications' ? Partner::where('status', 'applied')->with('user:id,name,email,created_at')->oldest()->limit(200)->get()->map(fn (Partner $p) => self::row($p) + [
                'promotion' => Partner::PROMOTION[$p->promotion_type] ?? $p->promotion_type,
                'promotion_note' => $p->promotion_note,
                'links' => $p->links ?? [],
                'agreement' => ['version' => $p->agreement_version, 'at' => $p->agreement_accepted_at?->toIso8601String()],
            ])->values() : [],
            'partners' => $tab === 'partners' ? Partner::whereIn('status', ['approved', 'suspended', 'declined'])
                ->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
                ->when($filters['q'], fn ($q, $term) => $q->where(fn ($w) => $w->where('display_name', 'ilike', '%'.addcslashes($term, '%_\\').'%')->orWhere('handle', 'ilike', '%'.addcslashes($term, '%_\\').'%')))
                ->with('user:id,name,email')->latest('approved_at')->paginate(30)->withQueryString()
                ->through(fn (Partner $p) => self::row($p) + ['stats' => Insights::stats($p), 'balances' => array_values($ledger->balances($p))]) : null,
            'commissions' => $tab === 'commissions' ? $this->ledgerQuery($filters)->with('partner:id,display_name,handle')->latest('id')->paginate(50)->withQueryString()
                ->through(fn (PartnerCommission $c) => self::commission($c)) : null,
            'eligible' => $tab === 'payouts' ? collect($ledger->eligible())->map(fn (array $row) => [
                'partner' => self::row($row['partner']),
                'payout_method' => Partner::PAYOUT_METHODS[$row['partner']->payout_method] ?? null,
            ] + array_diff_key($row, ['partner' => 1]))->values() : [],
            'payouts' => $tab === 'payouts' ? $ledger->payouts()->map(fn (Payout $p) => self::payoutRow($p))->values() : [],
            'settings' => $program->settings(),
            'filters' => $filters,
            'statuses' => PartnerCommission::STATUSES,
            'currencies' => config('commerce.currencies'),
            'methods' => collect(Payout::METHODS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'can_edit' => $request->user()->isPlatformOwner(),
        ]);
    }

    public function show(Request $request, Partner $partner, Ledger $ledger, PartnerPages $pages): Response
    {
        $partner->load('user:id,name,email,created_at', 'sellers:id,name');

        return Inertia::render('Admin/Business/PartnerShow', [
            'partner' => self::row($partner) + [
                'country' => $partner->country,
                'promotion' => Partner::PROMOTION[$partner->promotion_type] ?? $partner->promotion_type,
                'promotion_note' => $partner->promotion_note,
                'links' => $partner->links ?? [],
                'agreement' => ['version' => $partner->agreement_version, 'at' => $partner->agreement_accepted_at?->toIso8601String()],
                'restricted' => $partner->restricted,
                'sellers' => $partner->sellers->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values(),
                'decline_reason' => $partner->decline_reason,
                'suspend_reason' => $partner->suspend_reason,
                'balance_frozen' => $partner->balanceFrozen(),
                'payout_method' => Partner::PAYOUT_METHODS[$partner->payout_method] ?? null,
                // The owner pays by hand, so the owner sees where to; support
                // sees only that details exist.
                'payout_details' => $request->user()->isPlatformOwner() ? $partner->payout_details : ($partner->payout_details ? 'On file' : null),
                'tax_form_received_at' => $partner->tax_form_received_at?->toIso8601String(),
                'codes' => DiscountCode::where('partner_id', $partner->id)->get(['id', 'code', 'active'])->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'active' => $c->active])->values(),
            ],
            'page' => $pages->present($partner),
            'stats' => Insights::stats($partner),
            'timeline' => Insights::timeline($partner),
            'fraud' => Insights::fraud($partner),
            'balances' => array_values($ledger->balances($partner)),
            'eligibility' => collect($ledger->balances($partner))->mapWithKeys(fn ($b, $currency) => [$currency => $ledger->eligibility($partner, $currency)]),
            'commissions' => PartnerCommission::where('partner_id', $partner->id)->latest('id')->limit(100)->get()->map(fn (PartnerCommission $c) => self::commission($c))->values(),
            'payouts' => $ledger->payouts($partner)->map(fn (Payout $p) => self::payoutRow($p))->values(),
            'notes' => AdminNote::where('user_id', $partner->user_id)->with('author:id,name')->latest()->limit(50)->get()->map(fn (AdminNote $n) => [
                'id' => $n->id, 'body' => $n->body, 'pinned' => $n->pinned, 'author' => $n->author?->name, 'at' => $n->created_at?->toIso8601String(),
            ])->values(),
            'discount_codes' => DiscountCode::where('applies_to_storefront', true)->where('active', true)
                ->where(fn ($q) => $q->whereNull('partner_id')->orWhere('partner_id', $partner->id))
                ->orderBy('code')->limit(200)->get(['id', 'code'])->map(fn ($c) => ['id' => $c->id, 'code' => $c->code])->values(),
            'methods' => collect(Payout::METHODS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'can_edit' => $request->user()->isPlatformOwner(),
        ]);
    }

    // ── Support and owner ───────────────────────────────────────────────

    public function approve(Request $request, Partner $partner, Applications $applications): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $applications->approve($partner, $data['reason'] ?? null, $request->user());

        return back()->with('platform_notice', $partner->display_name.' is approved and has been told.');
    }

    public function decline(Request $request, Partner $partner, Applications $applications): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:4', 'max:500']]);
        $applications->decline($partner, $data['reason'], $request->user());

        return back()->with('platform_notice', $partner->display_name.' was declined and has been told why.');
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->ledgerQuery($filters)->with('partner:id,display_name,handle,code')->latest('id')->limit(20000);

        AdminAudit::transaction(fn () => AdminAudit::record('partners.commissions_exported', after: ['filters' => $filters]));

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'date', 'partner', 'handle', 'kind', 'book', 'currency', 'rate_percent', 'base_cents', 'amount_cents', 'reversed_cents', 'net_cents', 'status', 'via', 'hold_until', 'paid_at', 'order']);

            foreach ($query->cursor() as $c) {
                fputcsv($out, array_map([AuditLogController::class, 'cell'], [
                    $c->id,
                    $c->created_at?->toDateString(),
                    $c->partner?->display_name,
                    $c->partner?->handle,
                    $c->kind,
                    $c->title,
                    $c->currency,
                    $c->rate_bps / 100,
                    $c->base_cents,
                    $c->amount_cents,
                    $c->reversed_cents,
                    $c->net(),
                    $c->status,
                    $c->source,
                    $c->hold_until?->toDateString(),
                    $c->paid_at?->toDateString(),
                    $c->order_id,
                ]));
            }

            fclose($out);
        }, 'ecrivain-partner-commissions-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ── Owner ───────────────────────────────────────────────────────────

    public function suspend(Request $request, Partner $partner, Applications $applications): RedirectResponse
    {
        $applications->suspend($partner, $this->reason($request), $request->user());

        return back()->with('platform_notice', $partner->display_name.' is suspended. The page is offline and the balance frozen.');
    }

    public function reinstate(Request $request, Partner $partner, Applications $applications): RedirectResponse
    {
        $applications->reinstate($partner, $this->reason($request), $request->user());

        return back()->with('platform_notice', $partner->display_name.' is reinstated.');
    }

    public function release(Request $request, Partner $partner, Ledger $ledger): RedirectResponse
    {
        $ledger->release($partner, $this->reason($request), $request->user());

        return back()->with('platform_notice', 'Balance released: it can be paid.');
    }

    public function forfeit(Request $request, Partner $partner, Ledger $ledger): RedirectResponse
    {
        $ledger->forfeit($partner, $this->reason($request), $request->user());

        return back()->with('platform_notice', 'Balance forfeited.');
    }

    public function payout(Request $request, Partner $partner, Ledger $ledger): RedirectResponse
    {
        $data = $request->validate([
            'currency' => ['required', Rule::in(config('commerce.currencies'))],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'method' => ['required', Rule::in(array_keys(Payout::METHODS))],
            'reference' => ['nullable', 'string', 'max:255'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);

        $payout = $ledger->recordPayout($partner, [
            'currency' => $data['currency'],
            'amount_cents' => (int) round($data['amount'] * 100),
            'method' => $data['method'],
            'reference' => $data['reference'] ?? null,
            'paid_on' => $data['paid_on'],
            'note' => $data['note'] ?? null,
        ], $data['reason'], $request->user());

        return back()->with('platform_notice', 'Payout of '.number_format($payout->amount_cents / 100, 2).' '.strtoupper($payout->currency).' to '.$partner->display_name.' recorded.');
    }

    public function code(Request $request, Partner $partner, Applications $applications): RedirectResponse
    {
        $data = $request->validate([
            'discount_code_id' => ['nullable', 'integer', 'exists:discount_codes,id'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);

        $applications->attachCode($partner, isset($data['discount_code_id']) ? DiscountCode::find($data['discount_code_id']) : null, $data['reason'], $request->user());

        return back()->with('platform_notice', isset($data['discount_code_id']) ? 'Code attached.' : 'Codes detached.');
    }

    public function taxForm(Request $request, Partner $partner, Applications $applications): RedirectResponse
    {
        $data = $request->validate(['received' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:4', 'max:500']]);
        $applications->markTaxForm($partner, (bool) $data['received'], $data['reason'], $request->user());

        return back()->with('platform_notice', $data['received'] ? 'Tax form marked as received.' : 'Tax form mark removed.');
    }

    public function unrestrict(Request $request, Partner $partner, Applications $applications): RedirectResponse
    {
        $applications->unrestrict($partner, $this->reason($request), $request->user());

        return back()->with('platform_notice', $partner->display_name.' can now sell every book open to partners.');
    }

    public function settings(Request $request, Program $program): RedirectResponse
    {
        $data = $request->validate([
            'default_rate' => ['required', 'integer'],
            'min_rate' => ['required', 'integer'],
            'max_rate' => ['required', 'integer'],
            'hold_days' => ['required', 'integer'],
            'cookie_days' => ['required', 'integer'],
            'payout_minimum' => ['required', 'numeric', 'min:0', 'max:10000'],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ]);

        $program->update([
            'default_rate' => $data['default_rate'],
            'min_rate' => $data['min_rate'],
            'max_rate' => $data['max_rate'],
            'hold_days' => $data['hold_days'],
            'cookie_days' => $data['cookie_days'],
            'payout_minimum_cents' => (int) round($data['payout_minimum'] * 100),
        ], $data['reason'], $request->user());

        return back()->with('platform_notice', 'Program settings saved.');
    }

    // ── Internals ───────────────────────────────────────────────────────

    private function reason(Request $request): string
    {
        return $request->validate(['reason' => ['required', 'string', 'min:4', 'max:500']])['reason'];
    }

    /** @return array{status: ?string, currency: ?string, partner: ?int, q: ?string} */
    private function filters(Request $request): array
    {
        return [
            'status' => in_array($request->query('status'), [...PartnerCommission::STATUSES, ...Partner::STATUSES], true) ? $request->query('status') : null,
            'currency' => in_array($request->query('currency'), config('commerce.currencies'), true) ? $request->query('currency') : null,
            'partner' => is_numeric($request->query('partner')) ? (int) $request->query('partner') : null,
            'q' => mb_substr(trim((string) $request->query('q')), 0, 100) ?: null,
        ];
    }

    private function ledgerQuery(array $filters): Builder
    {
        return PartnerCommission::query()
            ->when(in_array($filters['status'], PartnerCommission::STATUSES, true), fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['currency'], fn ($q, $c) => $q->where('currency', $c))
            ->when($filters['partner'], fn ($q, $p) => $q->where('partner_id', $p));
    }

    /** @return array<string, mixed> */
    private static function row(Partner $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->display_name,
            'handle' => $p->handle,
            'code' => $p->code,
            'status' => $p->status,
            'url' => $p->pageUrl(),
            'account' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name, 'email' => $p->user->email] : null,
            'applied_at' => $p->created_at?->toIso8601String(),
            'approved_at' => $p->approved_at?->toIso8601String(),
            'tax_form' => $p->tax_form_received_at !== null,
        ];
    }

    /** @return array<string, mixed> */
    private static function commission(PartnerCommission $c): array
    {
        return [
            'id' => $c->id,
            'partner' => $c->relationLoaded('partner') && $c->partner ? ['id' => $c->partner->id, 'name' => $c->partner->display_name] : null,
            'kind' => $c->kind,
            'title' => $c->title,
            'date' => $c->created_at?->toDateString(),
            'currency' => $c->currency,
            'rate' => $c->rate_bps / 100,
            'base_cents' => $c->base_cents,
            'amount_cents' => $c->amount_cents,
            'reversed_cents' => $c->reversed_cents,
            'net_cents' => $c->net(),
            'status' => $c->status,
            'via' => $c->source,
            'hold_until' => $c->hold_until?->toDateString(),
            'order_id' => $c->order_id,
            'note' => $c->note,
        ];
    }

    /** @return array<string, mixed> */
    private static function payoutRow(Payout $p): array
    {
        return [
            'id' => $p->id,
            'account' => $p->user ? ['id' => $p->user->id, 'name' => $p->user->name, 'email' => $p->user->email] : null,
            'amount_cents' => $p->amount_cents,
            'currency' => $p->currency,
            'method' => Payout::METHODS[$p->method] ?? $p->method,
            'reference' => $p->reference,
            'paid_on' => $p->paid_on->toDateString(),
            'note' => $p->note,
            'by' => $p->recorder?->name,
        ];
    }
}
