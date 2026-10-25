<?php

namespace App\Platform;

use App\Billing\CreditLedger;
use App\Billing\PlanCatalog;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * What platform staff can do to an account.
 *
 * Each method makes its change and writes its audit row in one transaction,
 * so either both happen or neither does. Who may call which is decided by
 * the routes (owner-only gates) and, for amounts, here.
 */
final class AccountAdmin
{
    public function __construct(private readonly CreditLedger $ledger) {}

    /** Suspend: no sign-in, every session ended. */
    public function suspend(User $target, string $reason, User $actor): void
    {
        $this->refuseStaff($target, $actor);

        if ($target->isSuspended()) {
            throw ValidationException::withMessages(['reason' => 'This account is already suspended.']);
        }

        AdminAudit::transaction(function () use ($target, $reason, $actor) {
            $target->forceFill(['suspended_at' => now(), 'suspended_reason' => trim($reason)])->save();
            $this->revokeSessions($target);

            // A "View as" session on a suspended account ends too.
            DB::table('impersonation_sessions')
                ->where('target_id', $target->id)
                ->whereNull('ended_at')
                ->update(['ended_at' => now(), 'end_reason' => 'revoked', 'updated_at' => now()]);

            AdminAudit::record('account.suspended', $target, ['suspended' => false], ['suspended' => true], $reason, $actor);
        });
    }

    public function reactivate(User $target, string $reason, User $actor): void
    {
        if (! $target->isSuspended()) {
            throw ValidationException::withMessages(['reason' => 'This account is not suspended.']);
        }

        AdminAudit::transaction(function () use ($target, $reason, $actor) {
            $was = $target->suspended_reason;
            $target->forceFill(['suspended_at' => null, 'suspended_reason' => null])->save();

            AdminAudit::record('account.reactivated', $target, ['suspended' => true, 'suspended_reason' => $was], ['suspended' => false], $reason, $actor);
        });
    }

    /** End every session this account has, on every device. */
    public function signOutEverywhere(User $target, string $reason, User $actor): void
    {
        AdminAudit::transaction(function () use ($target, $reason, $actor) {
            $this->revokeSessions($target);

            AdminAudit::record('account.signed_out', $target, null, ['sessions_revoked_at' => $target->sessions_revoked_at->toIso8601String()], $reason, $actor);
        });
    }

    /**
     * Turn two-factor off for somebody who lost their phone and their codes.
     * Their sessions end too: whoever is signed in now may be the reason.
     */
    public function resetTwoFactor(User $target, string $reason, User $actor): void
    {
        $this->refuseStaff($target, $actor);

        if (! $target->two_factor_secret) {
            throw ValidationException::withMessages(['reason' => 'Two-factor sign-in is not on for this account.']);
        }

        AdminAudit::transaction(function () use ($target, $reason, $actor) {
            app(TwoFactor::class)->disable($target);
            $this->revokeSessions($target);

            AdminAudit::record('account.two_factor_reset', $target, ['two_factor' => true], ['two_factor' => false], $reason, $actor);
        });
    }

    /** Send the ordinary reset link, to the address on the account. */
    public function sendPasswordReset(User $target, User $actor): void
    {
        $this->refuseHouse($target, 'A house publisher account has no password and receives no email.');

        AdminAudit::transaction(function () use ($target, $actor) {
            AdminAudit::record('account.password_reset_sent', $target, null, ['email' => $target->email], null, $actor);

            $status = Password::broker()->sendResetLink(['email' => $target->email]);

            if ($status !== Password::RESET_LINK_SENT) {
                // Throttled or refused: the audit row rolls back with it.
                throw ValidationException::withMessages(['email' => __($status)]);
            }
        });
    }

    /**
     * Give a plan without payment, optionally until a date.
     *
     * Refused while the account pays Stripe: the subscription decides the
     * plan there, and a gift on top would be overwritten by the next invoice,
     * or worse, outlive a cancellation (PlanDraft's setPlanDraftPlan rule).
     *
     * $actor is null when the platform gives it on staff's behalf and the
     * staff account is gone (an accepted invitation whose sender left): the
     * audit row is then the system's.
     */
    public function setComplimentary(User $target, string $plan, ?Carbon $until, string $reason, ?User $actor): void
    {
        // Any paid plan in the catalogue, hidden and legacy ones included:
        // a plan no longer offered can still be given.
        if ($plan === 'free' || app(PlanCatalog::class)->get($plan) === null) {
            throw ValidationException::withMessages(['plan' => 'Choose a paid plan. To end a complimentary plan, clear it.']);
        }

        if ($this->hasActiveSubscription($target)) {
            throw ValidationException::withMessages([
                'plan' => 'This account pays through Stripe. Change the plan in Stripe (or cancel it there) instead; a complimentary plan would be overwritten by the next invoice.',
            ]);
        }

        if ($until && $until->isPast()) {
            throw ValidationException::withMessages(['until' => 'The end date has to be in the future.']);
        }

        if ($until && $until->gt(now()->addDays((int) config('platform.complimentary_max_days')))) {
            throw ValidationException::withMessages(['until' => 'At most '.config('platform.complimentary_max_days').' days ahead. Leave it empty for no end date.']);
        }

        AdminAudit::transaction(function () use ($target, $plan, $until, $reason, $actor) {
            $before = $this->planState($target);

            $target->forceFill([
                'plan' => $plan,
                'complimentary_since' => $target->complimentary_since ?? now(),
                'complimentary_until' => $until,
            ])->save();

            [$from, $to] = AdminAudit::diff($before, $this->planState($target));
            AdminAudit::record('plan.complimentary_set', $target, $from, $to, $reason, $actor, system: $actor === null);
        });
    }

    /** End a complimentary plan now: back to Free. */
    public function clearComplimentary(User $target, ?string $reason, ?User $actor, string $action = 'plan.complimentary_cleared'): void
    {
        if ($target->complimentary_since === null) {
            throw ValidationException::withMessages(['plan' => 'This account has no complimentary plan.']);
        }

        AdminAudit::transaction(function () use ($target, $reason, $actor, $action) {
            $before = $this->planState($target);

            $target->forceFill([
                'plan' => 'free',
                'complimentary_since' => null,
                'complimentary_until' => null,
            ])->save();

            [$from, $to] = AdminAudit::diff($before, $this->planState($target));
            AdminAudit::record($action, $target, $from, $to, $reason, $actor, system: $actor === null);
        });
    }

    /**
     * Add or take away credits, as a signed ledger row (ADR-0010).
     *
     * Support may add up to config('platform.credits.support_max_grant');
     * only the owner may take away, and never below zero.
     */
    public function adjustCredits(User $target, int $amount, string $reason, User $actor): CreditTransaction
    {
        if ($amount === 0) {
            throw ValidationException::withMessages(['amount' => 'Enter a number of credits other than zero.']);
        }

        $limit = $actor->isPlatformOwner()
            ? (int) config('platform.credits.owner_max_adjustment')
            : (int) config('platform.credits.support_max_grant');

        if ($amount < 0 && ! $actor->isPlatformOwner()) {
            throw ValidationException::withMessages(['amount' => 'Only the platform owner can take credits away.']);
        }

        if (abs($amount) > $limit) {
            throw ValidationException::withMessages(['amount' => "At most {$limit} credits in one adjustment."]);
        }

        return AdminAudit::transaction(function () use ($target, $amount, $reason, $actor) {
            // The same lock spend() takes, so a deduction and a review cannot
            // both pass the balance check.
            User::whereKey($target->id)->lockForUpdate()->first();
            $balance = $this->ledger->balanceFor($target);

            if ($amount < 0 && $balance + $amount < 0) {
                throw ValidationException::withMessages(['amount' => "The balance is {$balance}; a deduction cannot take it below zero."]);
            }

            $row = CreditTransaction::create([
                'user_id' => $target->id,
                'amount' => $amount,
                'kind' => $amount > 0 ? 'admin_grant' : 'admin_deduct',
                'label' => 'admin_adjustment',
            ]);

            AdminAudit::record(
                $amount > 0 ? 'credits.granted' : 'credits.deducted',
                $target,
                ['balance' => $balance],
                ['balance' => $balance + $amount, 'amount' => $amount, 'transaction_id' => $row->id],
                $reason,
                $actor,
            );

            return $row;
        });
    }

    /** Make an account support staff, or take it away. Owners are made only from the shell. */
    public function setRole(User $target, ?string $role, string $reason, User $actor): void
    {
        if (! in_array($role, [null, 'support'], true)) {
            throw ValidationException::withMessages(['role' => 'Owners are made from the shell: php artisan platform:admin.']);
        }

        if ($target->isPlatformOwner()) {
            throw ValidationException::withMessages(['role' => 'An owner is demoted from the shell: php artisan platform:demote.']);
        }

        $this->refuseHouse($target, 'A house publisher account cannot be staff: nobody signs in to it.', 'role');

        if ($target->platform_role === $role) {
            return;
        }

        AdminAudit::transaction(function () use ($target, $role, $reason, $actor) {
            $before = $target->platform_role;
            $target->forceFill(['platform_role' => $role])->save();
            // New powers, or fewer: either way, sign in again.
            $this->revokeSessions($target);

            AdminAudit::record('account.role_changed', $target, ['platform_role' => $before], ['platform_role' => $role], $reason, $actor);
        });
    }

    public function hasActiveSubscription(User $user): bool
    {
        return $user->subscriptions()->active()->exists();
    }

    /**
     * Sign the account out everywhere.
     *
     * The marker is what works on any session driver (EnsureAccountActive
     * compares it with the session's sign-in time); deleting database
     * session rows and rotating the remember token make it immediate and
     * stop a remember-me cookie from signing straight back in.
     */
    public function revokeSessions(User $user): void
    {
        $user->forceFill(['sessions_revoked_at' => now()])->setRememberToken(Str::random(60));
        $user->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }

    /** @return array<string, mixed> */
    private function planState(User $user): array
    {
        return [
            'plan' => $user->plan,
            'complimentary_since' => $user->complimentary_since?->toIso8601String(),
            'complimentary_until' => $user->complimentary_until?->toIso8601String(),
        ];
    }

    private function refuseStaff(User $target, User $actor): void
    {
        if ($target->is($actor)) {
            throw ValidationException::withMessages(['reason' => 'Not on your own account.']);
        }

        if ($target->isPlatformOwner()) {
            throw ValidationException::withMessages(['reason' => 'Not on an owner account: owners are managed from the shell.']);
        }
    }

    /** House publisher accounts (App\Platform\House) have no sign-in, mail or role. */
    private function refuseHouse(User $target, string $message, string $field = 'reason'): void
    {
        if ($target->isHouse()) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
