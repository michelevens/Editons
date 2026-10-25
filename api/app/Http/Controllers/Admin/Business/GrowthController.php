<?php

namespace App\Http\Controllers\Admin\Business;

use App\Billing\Payments;
use App\Billing\PlanCatalog;
use App\Growth\AudienceRules;
use App\Growth\Broadcasts;
use App\Growth\Discounts;
use App\Growth\Funnel;
use App\Growth\Lifecycle;
use App\Http\Controllers\Controller;
use App\Mail\Notify;
use App\Models\Audience;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\DiscountCode;
use App\Models\DiscountRedemption;
use App\Models\LifecycleCampaign;
use App\Models\User;
use App\Platform\AdminAudit;
use App\Platform\Mask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Growth: where accounts come from, audiences, broadcasts, discount codes
 * and lifecycle emails. All staff can look; every change is the owner's,
 * with a reason where it reaches people (sending, codes, campaigns) and an
 * audit row always.
 */
class GrowthController extends Controller
{
    private const REASON = ['required', 'string', 'min:4', 'max:500'];

    // ── Acquisition ─────────────────────────────────────────────────────

    public function acquisition(Request $request, Funnel $funnel): Response
    {
        $weeks = in_array((int) $request->query('weeks'), [4, 12, 26, 52], true) ? (int) $request->query('weeks') : 12;

        return Inertia::render('Admin/Business/Growth/Acquisition', [
            'funnel' => $funnel->report($weeks),
            'weeks' => $weeks,
            'recent' => User::whereNull('platform_role')->exceptHouse()
                ->latest('id')
                ->limit(20)
                ->get(['id', 'name', 'created_at', 'signup_source', 'signup_medium', 'signup_campaign', 'signup_referrer', 'signup_landing'])
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'at' => $u->created_at->toIso8601String(),
                    'source' => $u->signup_source,
                    'medium' => $u->signup_medium,
                    'campaign' => $u->signup_campaign,
                    'referrer' => $u->signup_referrer,
                    'landing' => $u->signup_landing,
                ]),
        ]);
    }

    // ── Audiences ───────────────────────────────────────────────────────

    public function audiences(Request $request, PlanCatalog $catalog): Response
    {
        $names = collect($catalog->all())->map(fn ($p) => $p['name'])->all();

        return Inertia::render('Admin/Business/Growth/Audiences', [
            'audiences' => Audience::latest('updated_at')->get()->map(fn (Audience $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'description' => $a->description,
                'rules' => AudienceRules::normalize($a->rules),
                'summary' => AudienceRules::describe($a->rules, $names),
                'count' => AudienceRules::query($a->rules)->count(),
                'reachable' => AudienceRules::allowing(AudienceRules::query($a->rules), 'news')->count(),
                'updated_at' => $a->updated_at->toIso8601String(),
            ]),
            'empty_rules' => AudienceRules::EMPTY,
            'options' => AudienceRules::options() + [
                'plans' => collect($catalog->all())->map(fn ($p, $key) => ['key' => $key, 'name' => $p['name']])->values(),
            ],
            'can_edit' => $request->user()->isPlatformOwner(),
        ]);
    }

    /** The live count while rules are being built. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['rules' => ['nullable', 'array']]);

        return response()->json(AudienceRules::preview($data['rules'] ?? []));
    }

    public function storeAudience(Request $request): RedirectResponse
    {
        $data = $request->validate($this->audienceRules());

        AdminAudit::transaction(function () use ($data, $request) {
            $audience = Audience::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'rules' => AudienceRules::normalize($data['rules'] ?? []),
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]);
            AdminAudit::record('audience.created', $audience, null, ['name' => $audience->name, 'rules' => $audience->rules], null, $request->user());
        });

        return back()->with('platform_notice', 'Audience saved.');
    }

    public function updateAudience(Request $request, Audience $audience): RedirectResponse
    {
        $data = $request->validate($this->audienceRules());

        AdminAudit::transaction(function () use ($data, $request, $audience) {
            $before = ['name' => $audience->name, 'description' => $audience->description, 'rules' => $audience->rules];
            $audience->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'rules' => AudienceRules::normalize($data['rules'] ?? []),
                'updated_by' => $request->user()->id,
            ]);
            [$from, $to] = AdminAudit::diff($before, ['name' => $audience->name, 'description' => $audience->description, 'rules' => $audience->rules]);
            AdminAudit::record('audience.updated', $audience, $from, $to, null, $request->user());
        });

        return back()->with('platform_notice', 'Audience saved.');
    }

    public function destroyAudience(Request $request, Audience $audience): RedirectResponse
    {
        if (Broadcast::where('audience_id', $audience->id)->whereIn('status', ['scheduled', 'sending'])->exists()) {
            return back()->withErrors(['audience' => 'A scheduled broadcast uses this audience. Cancel it first.']);
        }

        AdminAudit::transaction(function () use ($request, $audience) {
            AdminAudit::record('audience.deleted', $audience, ['name' => $audience->name, 'rules' => $audience->rules], null, null, $request->user());
            $audience->delete();
        });

        return back()->with('platform_notice', 'Audience deleted.');
    }

    // ── Broadcasts ──────────────────────────────────────────────────────

    public function broadcasts(Request $request): Response
    {
        return Inertia::render('Admin/Business/Growth/Broadcasts', [
            'broadcasts' => Broadcast::with(['audience:id,name', 'author:id,name'])->latest('id')->paginate(25)->through(fn (Broadcast $b) => self::broadcastRow($b)),
            'can_edit' => $request->user()->isPlatformOwner(),
        ]);
    }

    public function editBroadcast(Request $request, ?Broadcast $broadcast = null): Response
    {
        $recipients = $broadcast && ! $broadcast->editable()
            ? BroadcastRecipient::with('user:id,email')->where('broadcast_id', $broadcast->id)
                ->orderByRaw("case status when 'failed' then 0 when 'sending' then 1 when 'queued' then 2 when 'sent' then 3 else 4 end")
                ->latest('id')
                ->limit(100)
                ->get()
                ->map(fn (BroadcastRecipient $r) => [
                    'email' => Mask::email($r->user?->email),
                    'status' => $r->status,
                    'skip_reason' => $r->skip_reason,
                    'error' => $r->error,
                    'inboxed' => $r->inboxed,
                    'sent_at' => $r->sent_at?->toIso8601String(),
                ])
            : [];

        return Inertia::render('Admin/Business/Growth/BroadcastEdit', [
            'broadcast' => $broadcast ? self::broadcastRow($broadcast->load(['audience:id,name', 'author:id,name'])) + [
                'preheader' => $broadcast->preheader,
                'body' => $broadcast->body,
                'cta_label' => $broadcast->cta_label,
                'cta_url' => $broadcast->cta_url,
                'audience_id' => $broadcast->audience_id,
                'inbox' => $broadcast->inbox,
            ] : null,
            'recipients' => $recipients,
            'audiences' => Audience::orderBy('name')->get(['id', 'name', 'rules'])->map(fn (Audience $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'reachable' => AudienceRules::allowing(AudienceRules::query($a->rules), 'news')->count(),
            ]),
            'everyone_reachable' => AudienceRules::allowing(AudienceRules::query([]), 'news')->count(),
            'per_minute' => (int) config('growth.broadcasts.per_minute'),
            'can_edit' => $request->user()->isPlatformOwner(),
        ]);
    }

    public function storeBroadcast(Request $request, Broadcasts $broadcasts): RedirectResponse
    {
        $broadcast = $broadcasts->save(null, $this->broadcastData($request), $request->user());

        return redirect()->route('admin.growth.broadcasts.edit', $broadcast)->with('platform_notice', 'Draft saved.');
    }

    public function updateBroadcast(Request $request, Broadcast $broadcast, Broadcasts $broadcasts): RedirectResponse
    {
        $broadcasts->save($broadcast, $this->broadcastData($request), $request->user());

        return back()->with('platform_notice', 'Draft saved.');
    }

    public function scheduleBroadcast(Request $request, Broadcast $broadcast, Broadcasts $broadcasts): RedirectResponse
    {
        $data = $request->validate([
            'when' => ['required', Rule::in(['now', 'later'])],
            'scheduled_at' => ['required_if:when,later', 'nullable', 'date'],
            'reason' => self::REASON,
        ]);

        $at = $data['when'] === 'later' ? Carbon::parse($data['scheduled_at']) : null;
        $broadcasts->schedule($broadcast, $at, $data['reason'], $request->user());

        return back()->with('platform_notice', $at ? 'Scheduled for '.$at->toDayDateTimeString().'.' : 'Queued: it starts within a minute.');
    }

    public function cancelBroadcast(Request $request, Broadcast $broadcast, Broadcasts $broadcasts): RedirectResponse
    {
        $data = $request->validate(['reason' => self::REASON]);
        $broadcasts->cancel($broadcast, $data['reason'], $request->user());

        return back()->with('platform_notice', 'Broadcast cancelled.');
    }

    public function testBroadcast(Request $request, Broadcast $broadcast, Broadcasts $broadcasts): RedirectResponse
    {
        $sent = $broadcasts->testSend($broadcast, $request->user());

        return back()->with('platform_notice', $sent ? 'Test sent to '.$request->user()->email.'.' : 'The test could not be sent.');
    }

    /** The email as it will look, from the form as it stands (unsaved). */
    public function renderPreview(Request $request, Broadcasts $broadcasts): JsonResponse
    {
        $data = $this->broadcastData($request, forPreview: true);
        $draft = new Broadcast($data);

        return response()->json(['html' => $broadcasts->mail($draft, $request->user())->render()]);
    }

    // ── Discounts ───────────────────────────────────────────────────────

    public function discounts(Request $request, PlanCatalog $catalog, Discounts $discounts): Response
    {
        return Inertia::render('Admin/Business/Growth/Discounts', [
            'codes' => DiscountCode::latest('id')->get()->map(fn (DiscountCode $d) => [
                'id' => $d->id,
                'code' => $d->code,
                'description' => $d->description,
                'label' => $d->label(),
                'kind' => $d->kind,
                'applies_to_plans' => $d->applies_to_plans,
                'applies_to_storefront' => $d->applies_to_storefront,
                'plan_keys' => $d->plan_keys,
                'max_redemptions' => $d->max_redemptions,
                'used' => $discounts->used($d),
                'redeemed' => $d->redemptions()->count(),
                'expires_at' => $d->expires_at?->toIso8601String(),
                'expired' => $d->expires_at?->isPast() ?? false,
                'first_time_only' => $d->first_time_only,
                'active' => $d->active,
                'stripe_coupon_id' => $d->stripe_coupon_id,
            ]),
            'redemptions' => DiscountRedemption::with(['code:id,code', 'order:id,number'])->latest('id')->limit(100)->get()->map(fn (DiscountRedemption $r) => [
                'code' => $r->code?->code,
                'order' => $r->order?->number,
                'email' => Mask::email($r->email),
                'context' => $r->context,
                'amount_off_cents' => $r->amount_off_cents,
                'currency' => $r->currency,
                'at' => $r->created_at->toIso8601String(),
            ]),
            'plans' => collect($catalog->all())->except('free')->map(fn ($p, $key) => ['key' => $key, 'name' => $p['name']])->values(),
            'currencies' => config('commerce.currencies'),
            'payments' => ['available' => Payments::available()],
            'can_edit' => $request->user()->isPlatformOwner(),
        ]);
    }

    public function storeDiscount(Request $request, Discounts $discounts): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{3,40}$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'kind' => ['required', Rule::in(['percent', 'amount'])],
            'percent_off' => ['required_if:kind,percent', 'nullable', 'integer', 'between:1,100'],
            'amount_off' => ['required_if:kind,amount', 'nullable', 'numeric', 'min:0.01', 'max:100000'],
            'currency' => ['required_if:kind,amount', 'nullable', Rule::in(config('commerce.currencies'))],
            'applies_to_plans' => ['boolean'],
            'applies_to_storefront' => ['boolean'],
            'plan_keys' => ['nullable', 'array'],
            'plan_keys.*' => ['string', Rule::in(array_keys(app(PlanCatalog::class)->all()))],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:today'],
            'first_time_only' => ['boolean'],
            'reason' => self::REASON,
        ]);

        $code = $discounts->create($data + [
            'amount_off_cents' => isset($data['amount_off']) ? (int) round($data['amount_off'] * 100) : null,
        ], $data['reason'], $request->user());

        return back()->with('platform_notice', "Code {$code->code} created.");
    }

    public function updateDiscount(Request $request, DiscountCode $discount, Discounts $discounts): RedirectResponse
    {
        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'max_redemptions' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'expires_at' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'reason' => self::REASON,
        ]);

        $discounts->update($discount, $data, $data['reason'], $request->user());

        return back()->with('platform_notice', "Code {$discount->code} updated.");
    }

    // ── Lifecycle ───────────────────────────────────────────────────────

    public function lifecycle(Request $request, Lifecycle $lifecycle): Response
    {
        return Inertia::render('Admin/Business/Growth/Lifecycle', [
            'campaigns' => LifecycleCampaign::orderBy('id')->get()->map(fn (LifecycleCampaign $c) => [
                'id' => $c->id,
                'key' => $c->key,
                'name' => $c->name,
                'trigger' => $c->trigger,
                'trigger_label' => Lifecycle::TRIGGERS[$c->trigger] ?? $c->trigger,
                'delay_days' => $c->delay_days,
                'subject' => $c->subject,
                'body' => $c->body,
                'cta_label' => $c->cta_label,
                'enabled' => $c->enabled,
                'sent' => $c->sends()->where('status', 'sent')->count(),
                'skipped' => $c->sends()->where('status', 'skipped')->count(),
                'failed' => $c->sends()->where('status', 'failed')->count(),
                'due_now' => $lifecycle->eligible($c)->count(),
            ]),
            'window_days' => (int) config('growth.lifecycle.window_days'),
            'can_edit' => $request->user()->isPlatformOwner(),
        ]);
    }

    public function updateLifecycle(Request $request, LifecycleCampaign $campaign, Lifecycle $lifecycle): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'delay_days' => ['required', 'integer', 'min:1', 'max:365'],
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'cta_label' => ['nullable', 'string', 'max:60'],
            'enabled' => ['required', 'boolean'],
            'reason' => self::REASON,
        ]);

        $lifecycle->update($campaign, collect($data)->except('reason')->all(), $data['reason'], $request->user());

        return back()->with('platform_notice', $campaign->name.' '.($campaign->enabled ? 'is on.' : 'is off.'));
    }

    public function testLifecycle(Request $request, LifecycleCampaign $campaign, Lifecycle $lifecycle): RedirectResponse
    {
        $sent = Notify::send($request->user()->email, $lifecycle->mail($campaign, $request->user(), test: true));
        AdminAudit::transaction(fn () => AdminAudit::record('lifecycle.test_sent', $campaign, null, ['to' => $request->user()->email, 'sent' => $sent], null, $request->user()));

        return back()->with('platform_notice', $sent ? 'Test sent to '.$request->user()->email.'.' : 'The test could not be sent.');
    }

    // ── Internals ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function audienceRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'rules' => ['nullable', 'array'],
        ];
    }

    /** @return array<string, mixed> */
    private function broadcastData(Request $request, bool $forPreview = false): array
    {
        $data = $request->validate([
            'subject' => [$forPreview ? 'nullable' : 'required', 'string', 'max:150'],
            'preheader' => ['nullable', 'string', 'max:200'],
            'body' => [$forPreview ? 'nullable' : 'required', 'string', 'max:20000'],
            'cta_label' => ['nullable', 'required_with:cta_url', 'string', 'max:60'],
            // A link in a mass email goes somewhere safe: https only.
            'cta_url' => ['nullable', 'required_with:cta_label', 'url:https', 'max:500'],
            'audience_id' => ['nullable', 'integer', Rule::exists('audiences', 'id')],
            'inbox' => ['boolean'],
        ]);

        return [
            'subject' => (string) ($data['subject'] ?? ''),
            'preheader' => $data['preheader'] ?? null,
            'body' => (string) ($data['body'] ?? ''),
            'cta_label' => $data['cta_label'] ?? null,
            'cta_url' => $data['cta_url'] ?? null,
            'audience_id' => $data['audience_id'] ?? null,
            'inbox' => (bool) ($data['inbox'] ?? false),
        ];
    }

    /** @return array<string, mixed> */
    private static function broadcastRow(Broadcast $b): array
    {
        return [
            'id' => $b->id,
            'subject' => $b->subject,
            'status' => $b->status,
            'audience' => $b->audience_name ?? $b->audience?->name ?? 'Every account',
            'scheduled_at' => $b->scheduled_at?->toIso8601String(),
            'started_at' => $b->started_at?->toIso8601String(),
            'finished_at' => $b->finished_at?->toIso8601String(),
            'recipients_total' => $b->recipients_total,
            'sent' => $b->sent_count,
            'failed' => $b->failed_count,
            'skipped' => $b->skipped_count,
            'inbox' => $b->inbox,
            'author' => $b->author?->name,
            'created_at' => $b->created_at?->toIso8601String(),
        ];
    }
}
