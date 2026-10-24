<?php

namespace App\Http\Controllers\Admin\Business;

use App\Billing\Payments;
use App\Billing\PlanEditor;
use App\Editorial\EditorialAgent;
use App\Http\Controllers\Controller;
use App\Models\BookExport;
use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The plan catalogue: read by all staff, edited by the owner. A price change
 * is a new price (PlanEditor); the page shows each plan's price history and
 * how many accounts are on it.
 */
class PlansController extends Controller
{
    public function index(Request $request): Response
    {
        $accounts = DB::table('users')->select('plan', DB::raw('count(*) as n'))->groupBy('plan')->pluck('n', 'plan');
        $tiers = collect(EditorialAgent::cases())
            ->groupBy(fn (EditorialAgent $a) => $a->minimumTier())
            ->map(fn ($agents) => $agents->map(fn (EditorialAgent $a) => $a->label())->values())
            ->sortKeys();

        return Inertia::render('Admin/Business/Plans', [
            'plans' => Plan::with(['prices' => fn ($q) => $q->latest('id')])->orderBy('sort')->orderBy('id')->get()->map(fn (Plan $plan) => [
                'id' => $plan->id,
                'key' => $plan->key,
                'name' => $plan->name,
                'price_cents' => $plan->price_cents,
                'currency' => $plan->currency,
                'credits' => $plan->credits,
                'books' => $plan->books,
                'seats' => $plan->seats,
                'formats' => $plan->formats,
                'tier' => $plan->tier,
                'visibility' => $plan->visibility,
                'stripe_price_id' => $plan->stripe_price_id,
                'accounts' => (int) ($accounts[$plan->key] ?? 0),
                'prices' => $plan->prices->map(fn ($p) => [
                    'amount_cents' => $p->amount_cents,
                    'currency' => $p->currency,
                    'stripe_price_id' => $p->stripe_price_id,
                    'active_from' => $p->active_from->toIso8601String(),
                    'retired_at' => $p->retired_at?->toIso8601String(),
                ]),
            ]),
            'formats' => collect(BookExport::FORMATS)->map(fn ($f, $key) => ['key' => $key, 'label' => $f[0]])->values(),
            // What each specialist tier unlocks, so "tier 1" means something.
            'tiers' => $tiers->map(fn ($labels, $tier) => ['tier' => $tier, 'agents' => $labels])->values(),
            'payments' => ['available' => Payments::available(), 'refusal' => Payments::refusal()],
            'can_edit' => $request->user()->isPlatformOwner(),
        ]);
    }

    public function update(Request $request, Plan $plan, PlanEditor $editor): RedirectResponse
    {
        $data = $request->validate($this->rules());
        $result = $editor->update($plan, $this->values($data), $data['reason'], $request->user());

        return back()->with('platform_notice', $this->notice($result['plan'], $result['stripe']));
    }

    public function store(Request $request, PlanEditor $editor): RedirectResponse
    {
        $data = $request->validate($this->rules() + [
            'key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,31}$/', Rule::unique('plans', 'key')],
        ]);
        $result = $editor->create(['key' => $data['key']] + $this->values($data), $data['reason'], $request->user());

        return back()->with('platform_notice', 'Plan '.$result['plan']->name.' created. '.$this->stripeSentence($result['stripe']));
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        $maxTier = collect(EditorialAgent::cases())->max(fn (EditorialAgent $a) => $a->minimumTier());

        return [
            'name' => ['required', 'string', 'max:60'],
            'price' => ['required', 'numeric', 'min:0', 'max:10000'],
            'credits' => ['required', 'integer', 'min:0', 'max:10000000'],
            'books' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'formats' => ['present', 'array'],
            'formats.*' => ['string', Rule::in(array_keys(BookExport::FORMATS))],
            'tier' => ['required', 'integer', 'min:0', 'max:'.$maxTier],
            'visibility' => ['required', Rule::in(Plan::VISIBILITIES)],
            'reason' => ['required', 'string', 'min:4', 'max:500'],
        ];
    }

    /** @return array<string, mixed> */
    private function values(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'price_cents' => (int) round($data['price'] * 100),
            'credits' => (int) $data['credits'],
            'books' => $data['books'] ?? null,
            'seats' => $data['seats'] ?? null,
            'formats' => array_values(array_unique($data['formats'] ?? [])),
            'tier' => (int) $data['tier'],
            'visibility' => $data['visibility'],
        ];
    }

    private function notice(Plan $plan, ?string $stripe): string
    {
        return 'Plan '.$plan->name.' saved.'.($stripe ? ' '.$this->stripeSentence($stripe) : '');
    }

    private function stripeSentence(?string $stripe): string
    {
        return match ($stripe) {
            'created' => 'A new Stripe price was created for new subscribers; existing subscribers stay on theirs.',
            'not_configured' => 'Stripe is not configured, so no Stripe price was created; create one when it is.',
            default => '',
        };
    }
}
