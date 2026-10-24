<?php

namespace App\Http\Controllers\Admin\Trust;

use App\Http\Controllers\Controller;
use App\Models\LoginFailure;
use App\Models\User;
use App\Models\UserLogin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sign-in security across the platform: successes, failures, lockouts,
 * sign-ins from somewhere new, two-factor adoption, staff accounts and
 * signed-in sessions. Signing an account out is the phase-1 action
 * (admin.accounts.sign-out), offered beside each row.
 */
class SecurityController extends Controller
{
    private const WINDOWS = ['24h' => 1, '7d' => 7, '30d' => 30];

    public function index(Request $request): Response
    {
        $window = array_key_exists($request->query('window'), self::WINDOWS) ? (string) $request->query('window') : '7d';
        $since = now()->subDays(self::WINDOWS[$window]);

        $logins = UserLogin::where('created_at', '>=', $since)->where('impersonated', false);
        $failures = LoginFailure::where('created_at', '>=', $since);

        $events = UserLogin::where('created_at', '>=', $since)
            ->with('user:id,name,email')
            ->latest('id')
            ->limit(60)
            ->get()
            ->map(fn (UserLogin $l) => [
                'key' => 'in-'.$l->id,
                'type' => $l->impersonated ? 'view_as' : 'success',
                'method' => $l->method,
                'user' => $l->user ? ['id' => $l->user->id, 'name' => $l->user->name, 'email' => $l->user->email] : null,
                'ip' => $l->ip,
                'network' => $l->network,
                'country' => $l->country,
                'new_location' => $l->new_location,
                'agent' => $l->user_agent,
                'at' => $l->created_at?->toIso8601String(),
            ])
            ->concat(LoginFailure::where('created_at', '>=', $since)
                ->with('user:id,name,email')
                ->latest('id')
                ->limit(60)
                ->get()
                ->map(fn (LoginFailure $f) => [
                    'key' => 'fail-'.$f->id,
                    'type' => $f->kind === 'lockout' ? 'lockout' : 'failure',
                    'method' => $f->kind,
                    'user' => $f->user ? ['id' => $f->user->id, 'name' => $f->user->name, 'email' => $f->user->email] : null,
                    // The hash only: which address was typed is not kept.
                    'email_hash' => $f->email_hash ? substr($f->email_hash, 0, 10) : null,
                    'ip' => $f->ip,
                    'agent' => $f->user_agent,
                    'at' => $f->created_at?->toIso8601String(),
                ]))
            ->sortByDesc('at')
            ->take(80)
            ->values();

        $people = User::whereNull('offboarded_at');

        return Inertia::render('Admin/Trust/Security', [
            'window' => $window,
            'summary' => [
                'sign_ins' => (clone $logins)->count(),
                'accounts' => (clone $logins)->distinct('user_id')->count('user_id'),
                'new_locations' => (clone $logins)->where('new_location', true)->count(),
                'failed_passwords' => (clone $failures)->where('kind', 'password')->count(),
                'failed_codes' => (clone $failures)->where('kind', 'two_factor')->count(),
                'lockouts' => (clone $failures)->where('kind', 'lockout')->count(),
                'location_basis' => (clone $logins)->whereNotNull('country')->exists()
                    ? 'Country from Cloudflare (CF-IPCountry); network /16 when the edge gave none.'
                    : 'No country is reported here (no CF-IPCountry header), so "new location" means a new network: IPv4 /16 or IPv6 /32.',
            ],
            'top_ips' => (clone $failures)->select('ip', DB::raw('count(*) as n'), DB::raw('count(distinct email_hash) as addresses'))
                ->groupBy('ip')->orderByDesc('n')->limit(10)->get(),
            'top_targets' => (clone $failures)->whereNotNull('user_id')->select('user_id', DB::raw('count(*) as n'))
                ->groupBy('user_id')->orderByDesc('n')->limit(10)->with('user:id,name,email')->get()
                ->map(fn ($r) => ['user' => $r->user?->only(['id', 'name', 'email']), 'n' => (int) $r->n]),
            'events' => $events,
            'two_factor' => [
                'accounts' => (clone $people)->count(),
                'with' => (clone $people)->whereNotNull('two_factor_confirmed_at')->count(),
                'staff' => User::whereNotNull('platform_role')->count(),
                'staff_with' => User::whereNotNull('platform_role')->whereNotNull('two_factor_confirmed_at')->count(),
            ],
            'staff' => $this->staff(),
            'sessions' => $this->sessions(),
            'can' => ['owner' => $request->user()->isPlatformOwner()],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function staff(): array
    {
        $staff = User::whereNotNull('platform_role')->orderBy('platform_role')->orderBy('name')->get(['id', 'name', 'email', 'platform_role', 'two_factor_confirmed_at', 'suspended_at']);

        $last = UserLogin::whereIn('user_id', $staff->pluck('id'))->where('impersonated', false)
            ->select('user_id', DB::raw('max(created_at) as at'))->groupBy('user_id')->pluck('at', 'user_id');

        $sessions = $this->sessionCounts($staff->pluck('id')->all());

        return $staff->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->platform_role,
            'two_factor' => $u->two_factor_confirmed_at !== null,
            'suspended' => $u->suspended_at !== null,
            'last_sign_in' => isset($last[$u->id]) ? (string) $last[$u->id] : null,
            'sessions' => $sessions === null ? null : (int) ($sessions[$u->id] ?? 0),
        ])->all();
    }

    /**
     * Signed-in sessions per account, where the session driver lets us
     * count them (database sessions only).
     *
     * @return array{measurable: bool, note: string, top: list<array<string, mixed>>}
     */
    private function sessions(): array
    {
        if (config('session.driver') !== 'database') {
            return ['measurable' => false, 'note' => 'Sessions are kept by the "'.config('session.driver').'" driver, which cannot be counted from here.', 'top' => []];
        }

        $alive = now()->subMinutes((int) config('session.lifetime', 120))->getTimestamp();

        $rows = DB::table(config('session.table', 'sessions'))
            ->whereNotNull('user_id')
            ->where('last_activity', '>=', $alive)
            ->select('user_id', DB::raw('count(*) as n'), DB::raw('max(last_activity) as last'))
            ->groupBy('user_id')
            ->orderByDesc('n')
            ->limit(20)
            ->get();

        $users = User::whereIn('id', $rows->pluck('user_id'))->get(['id', 'name', 'email'])->keyBy('id');

        return [
            'measurable' => true,
            'note' => 'Database sessions active within the session lifetime ('.config('session.lifetime').' min). A session ended by "sign out everywhere" is deleted at once.',
            'top' => $rows->map(fn ($r) => [
                'user' => $users->get($r->user_id)?->only(['id', 'name', 'email']),
                'sessions' => (int) $r->n,
                'last_active' => date(DATE_ATOM, (int) $r->last),
            ])->all(),
        ];
    }

    /** @return array<int, int>|null */
    private function sessionCounts(array $ids): ?array
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        return DB::table(config('session.table', 'sessions'))->whereIn('user_id', $ids)
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime', 120))->getTimestamp())
            ->select('user_id', DB::raw('count(*) as n'))->groupBy('user_id')->pluck('n', 'user_id')->all();
    }
}
