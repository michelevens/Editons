<?php

namespace App\Http\Controllers\Admin;

use App\Billing\CreditLedger;
use App\Http\Controllers\Controller;
use App\Models\AccountDeletion;
use App\Models\AccountExport;
use App\Models\AdminNote;
use App\Models\Book;
use App\Models\BookExport;
use App\Models\CreditTransaction;
use App\Models\ImpersonationSession;
use App\Models\Order;
use App\Models\User;
use App\Models\UserLogin;
use App\Platform\AccountAdmin;
use App\Platform\Ai\AiControls;
use App\Platform\Ai\AiUsageReport;
use App\Platform\Features;
use App\Platform\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounts: find one, and see everything about it except its words.
 *
 * The account page shows counts — books, chapters, versions, exports — and
 * never manuscript text. Reading a book is what "View as" is for, and that
 * needs a reason and leaves a record; browsing one from here would not.
 */
class AccountsController extends Controller
{
    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $filter = (string) $request->query('filter', '');

        $users = User::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    if (ctype_digit($q)) {
                        $w->orWhere('id', (int) $q);
                    }

                    $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
                    $w->orWhere('email', 'ilike', $like)->orWhere('name', 'ilike', $like);
                });
            })
            ->when($filter === 'suspended', fn ($query) => $query->whereNotNull('suspended_at'))
            ->when($filter === 'staff', fn ($query) => $query->whereNotNull('platform_role'))
            ->when($filter === 'complimentary', fn ($query) => $query->whereNotNull('complimentary_since'))
            ->withCount(['books' => fn ($b) => $b->where('status', '!=', 'archived')])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $lastLogins = UserLogin::whereIn('user_id', $users->pluck('id'))
            ->where('impersonated', false)
            ->select('user_id', DB::raw('max(created_at) as at'))
            ->groupBy('user_id')
            ->pluck('at', 'user_id');

        return Inertia::render('Admin/Accounts/Index', [
            'q' => $q,
            'filter' => $filter,
            'accounts' => $users->through(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'plan' => $user->planLimits()['name'],
                'complimentary' => $user->complimentary_since !== null,
                'suspended' => $user->isSuspended(),
                'platform_role' => $user->platform_role,
                'books' => $user->books_count,
                'created_at' => $user->created_at?->toIso8601String(),
                'last_sign_in' => isset($lastLogins[$user->id]) ? (string) $lastLogins[$user->id] : null,
            ]),
        ]);
    }

    public function show(Request $request, User $user, CreditLedger $ledger, AccountAdmin $admin, AiUsageReport $report, AiControls $controls, Features $features): Response
    {
        $viewer = $request->user();
        $limits = $user->planLimits();
        $staff = (string) config('platform.staff_name');

        $books = Book::where('user_id', $user->id)
            ->withCount(['sections', 'exports'])
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get()
            ->map(fn (Book $book) => [
                'id' => $book->id,
                'title' => $book->title,
                'status' => $book->status,
                'words' => (int) $book->word_count,
                'sections' => $book->sections_count,
                'exports' => $book->exports_count,
                'versions' => (int) DB::table('manuscript_versions')
                    ->join('manuscript_sections', 'manuscript_sections.id', '=', 'manuscript_versions.manuscript_section_id')
                    ->where('manuscript_sections.book_id', $book->id)
                    ->count(),
                'updated_at' => $book->updated_at?->toIso8601String(),
            ]);

        $bookIds = Book::where('user_id', $user->id)->pluck('id');

        $sales = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.seller_id', $user->id)
            ->whereIn('orders.status', ['paid', 'partially_refunded', 'refunded'])
            ->select('orders.currency', DB::raw('count(*) as items'), DB::raw('sum(order_items.price_cents) as gross'))
            ->groupBy('orders.currency')
            ->get()
            ->map(fn ($row) => ['currency' => $row->currency, 'items' => (int) $row->items, 'gross_cents' => (int) $row->gross]);

        return Inertia::render('Admin/Accounts/Show', [
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'author_name' => $user->author_name,
                'created_at' => $user->created_at?->toIso8601String(),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'plan' => $user->plan,
                'plan_name' => $limits['name'],
                'complimentary_since' => $user->complimentary_since?->toIso8601String(),
                'complimentary_until' => $user->complimentary_until?->toIso8601String(),
                'stripe_subscription' => $admin->hasActiveSubscription($user),
                'suspended_at' => $user->suspended_at?->toIso8601String(),
                'suspended_reason' => $user->suspended_reason,
                'platform_role' => $user->platform_role,
                'two_factor' => $user->hasTwoFactor(),
                'sessions_revoked_at' => $user->sessions_revoked_at?->toIso8601String(),
            ],
            'credits' => [
                'balance' => $ledger->balanceFor($user),
                'monthly' => (int) ($limits['credits'] ?? 0),
                'ledger' => CreditTransaction::where('user_id', $user->id)
                    ->latest('id')
                    ->limit(50)
                    ->get()
                    ->map(fn (CreditTransaction $row) => [
                        'id' => $row->id,
                        'kind' => $row->kind,
                        'label' => $row->label,
                        'amount' => $row->amount,
                        'by_staff' => $row->impersonator_id !== null,
                        'at' => $row->created_at?->toIso8601String(),
                    ]),
            ],
            'books' => $books,
            'exports' => BookExport::whereIn('book_id', $bookIds)
                ->latest('id')
                ->limit(20)
                ->get(['id', 'book_id', 'format', 'status', 'created_at'])
                ->map(fn (BookExport $export) => [
                    'id' => $export->id,
                    'book_id' => $export->book_id,
                    'format' => $export->format,
                    'status' => $export->status,
                    'at' => $export->created_at?->toIso8601String(),
                ]),
            'orders' => Order::where('buyer_user_id', $user->id)
                ->latest('id')
                ->limit(10)
                ->get(['id', 'number', 'status', 'currency', 'total_cents', 'created_at'])
                ->map(fn (Order $order) => [
                    'number' => $order->number,
                    'status' => $order->status,
                    'currency' => $order->currency,
                    'total_cents' => $order->total_cents,
                    'at' => $order->created_at?->toIso8601String(),
                ]),
            'sales' => $sales,
            'logins' => UserLogin::where('user_id', $user->id)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (UserLogin $login) => [
                    'id' => $login->id,
                    'method' => $login->method,
                    // The author's history never pretends they signed in
                    // when staff did.
                    'label' => $login->impersonated ? "Viewed by {$staff}" : null,
                    'ip' => $login->ip,
                    'country' => $login->country,
                    'new_location' => $login->new_location,
                    'user_agent' => $login->user_agent,
                    'at' => $login->created_at?->toIso8601String(),
                ]),
            'notes' => AdminNote::where('user_id', $user->id)
                ->with('author:id,name')
                ->orderByDesc('pinned')
                ->latest('id')
                ->get()
                ->map(fn (AdminNote $note) => [
                    'id' => $note->id,
                    'body' => $note->body,
                    'pinned' => $note->pinned,
                    'author' => $note->author?->name,
                    'mine' => $note->author_id === $viewer->id,
                    'at' => $note->created_at?->toIso8601String(),
                ]),
            'impersonations' => ImpersonationSession::where('target_id', $user->id)
                ->with('impersonator:id,name')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (ImpersonationSession $s) => self::sessionRow($s)),
            'can' => [
                'owner' => $viewer->isPlatformOwner(),
                'impersonate' => Impersonation::refusal($viewer, $user),
                'max_grant' => $viewer->isPlatformOwner()
                    ? (int) config('platform.credits.owner_max_adjustment')
                    : (int) config('platform.credits.support_max_grant'),
            ],
            'impersonation_minutes' => Impersonation::minutes(),
            // What this account's AI calls cost, and its cap.
            'ai' => [
                'windows' => $report->windows($user),
                'by_feature' => $report->by('feature', 30, $user),
                'failures' => $report->recentFailures(10, $user),
                'cap' => $user->ai_monthly_cap_usd === null ? null : (float) $user->ai_monthly_cap_usd,
                'effective_cap' => $controls->capFor($user),
                'spent_this_month' => round($controls->spentThisMonth($user), 4),
            ],
            // Its data export and deletion (Trust & operations → Offboarding).
            'offboarding' => [
                'deletion' => ($d = AccountDeletion::where('user_id', $user->id)->latest('id')->first())
                    ? ['status' => $d->status, 'scheduled_for' => $d->scheduled_for?->toIso8601String()]
                    : null,
                'export' => ($e = AccountExport::where('user_id', $user->id)->latest('id')->first())
                    ? ['status' => $e->status, 'at' => $e->created_at?->toIso8601String()]
                    : null,
            ],
            // Each module for this account, and whether an override decides it.
            'features' => collect(Features::catalog())->map(fn (array $f) => $f + $features->explain($f['key'], $user) + [
                'override' => $features->overridesFor($user)[$f['key']] ?? null,
            ])->values(),
        ]);
    }

    /** @return array<string, mixed> */
    public static function sessionRow(ImpersonationSession $s): array
    {
        return [
            'id' => $s->id,
            'impersonator' => $s->impersonator?->name,
            'target' => $s->relationLoaded('target') ? ['id' => $s->target?->id, 'name' => $s->target?->name, 'email' => $s->target?->email] : null,
            'reason' => $s->reason,
            'started_at' => $s->started_at?->toIso8601String(),
            'expires_at' => $s->expires_at?->toIso8601String(),
            'ended_at' => $s->ended_at?->toIso8601String(),
            'end_reason' => $s->end_reason ?? ($s->isLive() ? 'live' : 'expired'),
            'ip' => $s->ip,
        ];
    }
}
