<?php

namespace App\Platform;

use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * "View as": a platform admin sees Écrivain exactly as one author does.
 *
 * Adapted from Credentik's design (a token minted on the target) to session
 * auth: the admin's session is switched to the target, with the admin's id
 * and an impersonation_sessions row kept in it, and switched back on stop or
 * expiry. Everything the target can open behaves as it does for them, which
 * is the point; what they should do themselves is refused for the whole
 * session by one path list (BLOCKED), checked by GuardImpersonation on every
 * request — not by remembering to check in each controller (ShiftPulse's
 * lesson).
 *
 * Rules: a reason is required; 30–60 minutes, never extended; never
 * yourself, another platform account or a suspended one; and the admin must
 * have two-factor on.
 */
final class Impersonation
{
    /** Session key: [session_id, admin_id]. */
    public const SESSION_KEY = 'platform.impersonation';

    /** Request attribute memo for the session row. */
    private const ATTR = 'platform.impersonation.row';

    /**
     * Refused while viewing as somebody: [methods (null = any), patterns, label].
     *
     * Money, credentials, identity, anything irreversible or that leaves the
     * platform under the author's name, and copies of their work. Support
     * reads a manuscript on screen; it does not take one away.
     */
    private const BLOCKED = [
        [null, ['admin', 'admin/*'], 'the admin console'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], ['billing', 'billing/*', 'stripe/*'], 'billing and subscriptions'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], ['store/*/checkout', 'store/checkout/*'], 'checkout'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], ['payouts', 'payouts/*'], 'payouts'],
        // Écrivain Partners: accepting the agreement, payout details, the
        // page under the partner's name, invitations and blocks.
        [['POST', 'PUT', 'PATCH', 'DELETE'], ['partners/apply', 'partners/invitations/*', 'partner', 'partner/*', 'sales/partners/*'], 'the partner program'],
        [['PUT'], ['password'], 'password changes'],
        [null, ['user/two-factor', 'user/two-factor/*'], 'two-factor settings'],
        [['PATCH', 'DELETE'], ['profile'], 'email and account changes'],
        [['POST', 'PATCH'], ['profile/email-preferences', 'email/unsubscribe/*'], 'email preferences'],
        // A pattern in #…# is a regular expression: Str::is's * crosses
        // slashes, and "books/*" would also refuse removing a cover image.
        [['DELETE'], ['#^books/\d+$#'], 'deleting a book'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], ['books/*/submit', 'books/*/channels', 'books/*/channels/*'], 'publishing to stores'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], ['books/*/product'], 'storefront products'],
        [['POST', 'PUT', 'PATCH', 'DELETE'], ['books/*/members', 'members/*', 'invitations/*', 'join/*'], 'team invitations and joining from an invitation'],
        [null, [
            'books/*/exports/*/download', 'books/*/export.md', 'books/*/export.ecrivain',
            'books/*/cover/download', 'store/downloads/*',
        ], 'downloading the author\'s files'],
    ];

    public static function minutes(): int
    {
        return (int) config('platform.impersonation.minutes', 30);
    }

    /** Is the current request a "View as" session? */
    public static function active(?Request $request = null): bool
    {
        return self::current($request) !== null;
    }

    /**
     * The live row behind this session, if any. Not checked for expiry here:
     * GuardImpersonation does that, and ends the session when it is over.
     */
    public static function current(?Request $request = null): ?ImpersonationSession
    {
        $request ??= request();

        if (! $request->hasSession()) {
            return null;
        }

        $state = $request->session()->get(self::SESSION_KEY);

        if (! is_array($state) || ! isset($state['session_id'])) {
            return null;
        }

        if (! $request->attributes->has(self::ATTR)) {
            $row = ImpersonationSession::find($state['session_id']);
            $request->attributes->set(self::ATTR, $row && $row->ended_at === null ? $row : false);
        }

        return $request->attributes->get(self::ATTR) ?: null;
    }

    /** Who is really at the keyboard, when it is not the signed-in account. */
    public static function impersonatorId(): ?int
    {
        if (! app()->bound('request')) {
            return null;
        }

        return self::current()?->impersonator_id;
    }

    /** Why $admin may not view as $target, or null if they may. */
    public static function refusal(User $admin, User $target): ?string
    {
        return match (true) {
            ! $admin->isPlatformAdmin() => 'Only platform staff can view as an account.',
            self::active() => 'Stop viewing the current account first.',
            ! $admin->hasTwoFactor() => 'Turn on two-factor sign-in for your own account before viewing as anybody.',
            $admin->is($target) => 'That is your own account.',
            $target->isPlatformAdmin() => 'Platform staff accounts cannot be viewed as.',
            // Nobody signs in to a house account, so there is no "as them" to
            // see: the owner edits its books directly, as themselves.
            $target->isHouse() => 'This is a house publisher account. Open its books from the House catalog instead.',
            $target->isSuspended() => 'This account is suspended. Reactivate it first if you need to see it.',
            default => null,
        };
    }

    /**
     * Switch this browser session to the target.
     *
     * The row and its audit entry are written first, in one transaction; only
     * then is the session switched, so a failure leaves the admin signed in
     * as themselves with nothing half-done.
     */
    public static function start(Request $request, User $admin, User $target, string $reason): ImpersonationSession
    {
        $session = DB::transaction(function () use ($request, $admin, $target, $reason) {
            $session = ImpersonationSession::create([
                'impersonator_id' => $admin->id,
                'target_id' => $target->id,
                'reason' => trim($reason),
                'started_at' => now(),
                'expires_at' => now()->addMinutes(self::minutes()),
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            ]);

            AdminAudit::record(
                'impersonation.started',
                $target,
                after: ['session_id' => $session->id, 'expires_at' => $session->expires_at->toIso8601String()],
                reason: $reason,
                actor: $admin,
            );

            return $session;
        });

        Auth::guard('web')->login($target);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, [
            'session_id' => $session->id,
            'admin_id' => $admin->id,
        ]);
        $request->attributes->set(self::ATTR, $session);

        LoginHistory::record($target, 'impersonation', $request, impersonator: $admin);

        return $session;
    }

    /**
     * End the session and put the admin back.
     *
     * Returns the admin, signed in again, or null when the admin can no longer
     * be (demoted, suspended or deleted since): then the browser is simply
     * signed out, because handing a former admin their session back is the
     * one outcome worse than an abrupt sign-out.
     */
    public static function stop(Request $request, string $endReason = 'stopped'): ?User
    {
        $state = $request->session()->get(self::SESSION_KEY);
        $row = is_array($state) ? ImpersonationSession::find($state['session_id'] ?? 0) : null;
        $admin = $row ? User::find($row->impersonator_id) : null;

        if ($row && $row->ended_at === null) {
            DB::transaction(function () use ($row, $admin, $endReason) {
                $row->forceFill(['ended_at' => now(), 'end_reason' => $endReason])->save();

                AdminAudit::record(
                    'impersonation.ended',
                    $row->target,
                    after: ['session_id' => $row->id, 'end_reason' => $endReason],
                    actor: $admin,
                );
            });
        }

        $request->session()->forget(self::SESSION_KEY);
        $request->attributes->set(self::ATTR, false);

        if ($admin && $admin->isPlatformAdmin() && ! $admin->isSuspended()) {
            Auth::guard('web')->login($admin);
            $request->session()->regenerate();

            return $admin;
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return null;
    }

    /** The label of what this request would do, if it is refused while viewing. */
    public static function blockedAction(Request $request): ?string
    {
        $method = strtoupper($request->method());

        foreach (self::BLOCKED as [$methods, $patterns, $label]) {
            if ($methods !== null && ! in_array($method, $methods, true)) {
                continue;
            }

            foreach ($patterns as $pattern) {
                $matches = str_starts_with($pattern, '#')
                    ? preg_match($pattern, $request->path()) === 1
                    : $request->is($pattern);

                if ($matches) {
                    return $label;
                }
            }
        }

        return null;
    }

    /** The banner's data, for HandleInertiaRequests. */
    public static function banner(Request $request): ?array
    {
        $row = self::current($request);

        if (! $row) {
            return null;
        }

        $target = $request->user();

        return [
            'session_id' => $row->id,
            'target' => ['id' => $target?->id, 'name' => $target?->name, 'email' => $target?->email],
            'admin' => ['name' => $row->impersonator?->name],
            'reason' => $row->reason,
            'started_at' => $row->started_at->toIso8601String(),
            'expires_at' => $row->expires_at->toIso8601String(),
        ];
    }
}
