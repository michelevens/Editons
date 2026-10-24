<?php

namespace App\Growth\Invitations;

use App\Billing\CreditLedger;
use App\Billing\PlanCatalog;
use App\Inbox\Inbox;
use App\Mail\Growth\AuthorInvitationMail;
use App\Mail\Notify;
use App\Models\AdminAuditLog;
use App\Models\AuthorInvitation;
use App\Models\AuthorInvitationBatch;
use App\Models\User;
use App\Platform\AccountAdmin;
use App\Platform\AdminAudit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Inviting people to join Écrivain, from the console (ADR — author
 * invitations; docs/ADMIN.md, "Invitations").
 *
 * Who may be invited: an address with no account, no invitation already
 * pending, and not on the do-not-invite list (people who clicked "Don't
 * invite me again"). The checks are made when a list is previewed and again
 * when it is sent, and the database refuses a second pending invitation
 * for one address whatever the checks said.
 *
 * Every send, resend and revoke writes its audit row in the same
 * transaction as the change. The email is handed to the mailer (queued)
 * after the row is committed, so a mail is never sent for an invitation
 * that was rolled back.
 *
 * Perks are what the person gets when they accept: a complimentary plan
 * for some months (owner only) and AI credits (support up to its grant
 * limit). They are applied at acceptance, in the same transaction as the
 * account and the acceptance, through the console's own mechanisms
 * (AccountAdmin::setComplimentary, the credit ledger's admin_grant), so the
 * account page, billing and the audit log read as if staff had given them.
 */
final class Invitations
{
    public function __construct(
        private readonly AccountAdmin $accounts,
        private readonly CreditLedger $ledger,
        private readonly PlanCatalog $plans,
    ) {}

    // ── Who can be invited ──────────────────────────────────────────────

    public static function normalizeEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    public static function validEmail(string $email): bool
    {
        return $email !== '' && mb_strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Why this address cannot be invited, or null.
     *
     * @return array{code: string, message: string, user_id?: int, invitation_id?: int}|null
     */
    public function refusal(string $email): ?array
    {
        $email = self::normalizeEmail($email);

        if (! self::validEmail($email)) {
            return ['code' => 'invalid', 'message' => 'Not a valid email address.'];
        }

        $userId = User::whereRaw('lower(email) = ?', [$email])->value('id');

        if ($userId) {
            return ['code' => 'member', 'message' => 'Already a member: this address has an account.', 'user_id' => (int) $userId];
        }

        $pending = AuthorInvitation::where('email', $email)->where('status', 'pending')->first();

        if ($pending && $pending->isLive()) {
            return [
                'code' => 'pending',
                'message' => 'Already invited on '.($pending->sent_at ?? $pending->created_at)->toDateString().'. Resend that invitation instead.',
                'invitation_id' => $pending->id,
            ];
        }

        if ($this->optedOut($email)) {
            return ['code' => 'opted_out', 'message' => 'Asked not to be invited again.'];
        }

        return null;
    }

    public function optedOut(string $email): bool
    {
        return DB::table('author_invitation_opt_outs')->where('email_hash', self::emailHash($email))->exists();
    }

    /** The do-not-invite list keeps an HMAC, never the address. */
    public static function emailHash(string $email): string
    {
        return hash_hmac('sha256', self::normalizeEmail($email), (string) config('app.key'));
    }

    // ── Perks and limits ────────────────────────────────────────────────

    /**
     * Check the perks against the sender's role and return them in the
     * stored shape, or null for none.
     *
     * @param  array{plan?: ?string, plan_months?: mixed, credits?: mixed}  $perks
     * @return array{plan: ?string, plan_months: ?int, credits: ?int}|null
     */
    public function perks(array $perks, User $actor): ?array
    {
        $plan = trim((string) ($perks['plan'] ?? '')) ?: null;
        $months = $plan ? (int) ($perks['plan_months'] ?? 0) : null;
        $credits = (int) ($perks['credits'] ?? 0) ?: null;

        if ($plan !== null) {
            if (! $actor->isPlatformOwner()) {
                throw ValidationException::withMessages(['plan' => 'Only the platform owner can include a plan in an invitation.']);
            }

            if ($plan === 'free' || $this->plans->get($plan) === null) {
                throw ValidationException::withMessages(['plan' => 'Choose a paid plan from the catalogue.']);
            }

            $max = (int) config('growth.invitations.max_plan_months');

            if ($months < 1 || $months > $max) {
                throw ValidationException::withMessages(['plan_months' => "Give the plan for 1 to {$max} months."]);
            }
        }

        if ($credits !== null) {
            $limit = $actor->isPlatformOwner()
                ? (int) config('platform.credits.owner_max_adjustment')
                : (int) config('platform.credits.support_max_grant');

            if ($credits < 1 || $credits > $limit) {
                throw ValidationException::withMessages(['credits' => "Credits: 1 to {$limit} for your role."]);
            }
        }

        return $plan === null && $credits === null ? null : ['plan' => $plan, 'plan_months' => $months, 'credits' => $credits];
    }

    /** Invitations this person may still send today (resends count). */
    public function remainingToday(User $actor): int
    {
        $limit = (int) config('growth.invitations.daily_limit.'.($actor->isPlatformOwner() ? 'owner' : 'support'));

        $used = AdminAuditLog::where('actor_id', $actor->id)
            ->whereIn('action', ['invitation.sent', 'invitation.resent'])
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return max(0, $limit - $used);
    }

    private function guardDaily(User $actor, int $wanted): void
    {
        $left = $this->remainingToday($actor);

        if ($wanted > $left) {
            throw ValidationException::withMessages(['email' => $left === 0
                ? 'You have reached your invitation limit for the last 24 hours.'
                : "You can send {$left} more invitation".($left === 1 ? '' : 's').' in the next 24 hours.']);
        }
    }

    private static function days(?int $days): int
    {
        $days ??= (int) config('growth.invitations.default_days');
        $max = (int) config('growth.invitations.max_days');

        if ($days < 1 || $days > $max) {
            throw ValidationException::withMessages(['expires_days' => "The link can stay open 1 to {$max} days."]);
        }

        return $days;
    }

    // ── Sending ─────────────────────────────────────────────────────────

    /**
     * Invite one person.
     *
     * @param  array{email: string, name?: ?string, language?: ?string, note?: ?string, perks?: array<string, mixed>, expires_days?: ?int, campaign?: ?string}  $data
     */
    public function invite(array $data, User $actor): AuthorInvitation
    {
        $email = self::normalizeEmail($data['email']);

        if ($refusal = $this->refusal($email)) {
            throw ValidationException::withMessages(['email' => $refusal['message']]);
        }

        $perks = $this->perks($data['perks'] ?? [], $actor);
        $days = self::days(isset($data['expires_days']) ? (int) $data['expires_days'] : null);
        $this->guardDaily($actor, 1);

        [$invitation, $token] = $this->create([
            'email' => $email,
            'name' => $data['name'] ?? null,
            'language' => $data['language'] ?? 'en',
            'note' => $data['note'] ?? null,
            'perks' => $perks,
            'campaign' => $data['campaign'] ?? null,
        ], $days, $actor, null);

        $this->send($invitation, $token);

        return $invitation;
    }

    /**
     * Read a pasted list or a CSV file: one person per line, as
     * email[,name[,language]] (comma, semicolon or tab). A first line whose
     * first cell is "email" is a header. Each row says whether it can be
     * sent, and why not.
     *
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>, too_many: bool, max: int}
     */
    public function preview(string $text): array
    {
        $max = (int) config('growth.invitations.batch_max');
        $rows = [];
        $seen = [];
        $lines = preg_split('/\r\n|\r|\n/', trim(self::stripBom($text))) ?: [];
        $lineNo = 0;

        foreach ($lines as $line) {
            $lineNo++;

            if (trim($line) === '') {
                continue;
            }

            $cells = array_map('trim', str_getcsv($line, self::separator($line), '"', ''));

            if ($lineNo === 1 && in_array(mb_strtolower($cells[0] ?? ''), ['email', 'e-mail', 'courriel', 'imèl'], true)) {
                continue;
            }

            $email = self::normalizeEmail($cells[0] ?? '');
            $name = mb_substr(strip_tags($cells[1] ?? ''), 0, 255) ?: null;
            $language = mb_strtolower($cells[2] ?? '') ?: null;

            $row = ['line' => $lineNo, 'email' => $email, 'name' => $name, 'language' => $language, 'status' => 'ok', 'message' => null];

            if ($language !== null && ! array_key_exists($language, AuthorInvitation::LANGUAGES)) {
                $row = ['status' => 'invalid', 'message' => 'Language must be en, fr or ht.'] + $row;
            } elseif (isset($seen[$email])) {
                $row = ['status' => 'duplicate', 'message' => 'Also on line '.$seen[$email].'.'] + $row;
            } elseif ($refusal = $this->refusal($email)) {
                $row = ['status' => $refusal['code'], 'message' => $refusal['message']] + $row + array_intersect_key($refusal, array_flip(['user_id', 'invitation_id']));
            }

            if (self::validEmail($email)) {
                $seen[$email] ??= $lineNo;
            }

            $rows[] = $row;
        }

        $summary = array_count_values(array_column($rows, 'status'));

        return [
            'rows' => $rows,
            'summary' => ['total' => count($rows), 'ok' => $summary['ok'] ?? 0] + $summary,
            'too_many' => ($summary['ok'] ?? 0) > $max,
            'max' => $max,
        ];
    }

    /**
     * Send a previewed list. The rows are checked again — the preview is
     * advice, not a promise — and only the ones still sendable go.
     *
     * @param  list<array{email: string, name?: ?string, language?: ?string}>  $rows
     * @param  array{language?: ?string, note?: ?string, perks?: array<string, mixed>, expires_days?: ?int, campaign?: ?string}  $shared
     */
    public function bulk(array $rows, array $shared, string $source, User $actor): AuthorInvitationBatch
    {
        $max = (int) config('growth.invitations.batch_max');

        if (count($rows) > $max) {
            throw ValidationException::withMessages(['rows' => "At most {$max} people in one batch. Split the list."]);
        }

        $perks = $this->perks($shared['perks'] ?? [], $actor);
        $days = self::days(isset($shared['expires_days']) ? (int) $shared['expires_days'] : null);

        // Re-check every row from scratch, the way preview does.
        $text = implode("\n", array_map(
            fn (array $r) => implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', [$r['email'] ?? '', $r['name'] ?? '', $r['language'] ?? ''])),
            $rows,
        ));
        $checked = $this->preview($text)['rows'];
        $sendable = array_values(array_filter($checked, fn (array $r) => $r['status'] === 'ok'));

        if ($sendable === []) {
            throw ValidationException::withMessages(['rows' => 'Nobody on this list can be invited.']);
        }

        $this->guardDaily($actor, count($sendable));
        $campaign = trim((string) ($shared['campaign'] ?? '')) ?: null;

        $batch = AdminAudit::transaction(function () use ($campaign, $source, $actor, $checked) {
            $batch = AuthorInvitationBatch::create([
                'campaign' => $campaign,
                'source' => $source,
                'created_by' => $actor->id,
                'rows_total' => count($checked),
            ]);

            AdminAudit::record('invitation.batch_created', $batch, null, ['rows' => count($checked), 'campaign' => $campaign, 'source' => $source], null, $actor);

            return $batch;
        });

        $sent = 0;

        foreach ($sendable as $row) {
            try {
                [$invitation, $token] = $this->create([
                    'email' => $row['email'],
                    'name' => $row['name'],
                    'language' => $row['language'] ?? ($shared['language'] ?? 'en'),
                    'note' => $shared['note'] ?? null,
                    'perks' => $perks,
                    'campaign' => $campaign,
                ], $days, $actor, $batch);
            } catch (ValidationException) {
                continue; // invited by somebody else a moment ago
            }

            $this->send($invitation, $token);
            $sent++;
        }

        $batch->forceFill(['sent' => $sent, 'skipped' => count($checked) - $sent])->save();

        return $batch;
    }

    /**
     * The row and its audit row, together. Returns the raw token, which is
     * not stored anywhere.
     *
     * @return array{0: AuthorInvitation, 1: string}
     */
    private function create(array $values, int $days, User $actor, ?AuthorInvitationBatch $batch): array
    {
        $token = Str::random(48);

        try {
            $invitation = AdminAudit::transaction(function () use ($values, $days, $actor, $batch, $token) {
                // An expired-but-unswept pending row would block the partial
                // unique index: settle it first.
                AuthorInvitation::where('email', $values['email'])
                    ->where('status', 'pending')
                    ->where('expires_at', '<=', now())
                    ->update(['status' => 'expired', 'expired_at' => now(), 'updated_at' => now()]);

                $invitation = AuthorInvitation::create([
                    'email' => $values['email'],
                    'name' => trim((string) $values['name']) ?: null,
                    'language' => array_key_exists($values['language'] ?? '', AuthorInvitation::LANGUAGES) ? $values['language'] : 'en',
                    'note' => trim((string) $values['note']) ?: null,
                    'perks' => $values['perks'],
                    'token_hash' => AuthorInvitation::hashToken($token),
                    'status' => 'pending',
                    'invited_by' => $actor->id,
                    'batch_id' => $batch?->id,
                    'campaign' => $values['campaign'] ?? null,
                    'expires_at' => now()->addDays($days),
                ]);

                AdminAudit::record('invitation.sent', $invitation, null, [
                    'email' => $invitation->email,
                    'language' => $invitation->language,
                    'perks' => $invitation->perks,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                    'batch_id' => $batch?->id,
                ], null, $actor);

                return $invitation;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'Already invited a moment ago. Resend that invitation instead.']);
        }

        return [$invitation, $token];
    }

    /** Hand the email to the mailer and note when. */
    private function send(AuthorInvitation $invitation, string $token): void
    {
        $invitation->loadMissing('inviter');

        if (Notify::send($invitation->email, new AuthorInvitationMail($invitation, $token))) {
            $invitation->forceFill(['sent_at' => $invitation->sent_at ?? now()])->save();
        }
    }

    /**
     * Send it again, with a new link (the old one stops working) and a new
     * expiry. An invitation that expired can be revived this way; one that
     * was accepted, revoked or declined cannot.
     */
    public function resend(AuthorInvitation $invitation, User $actor): AuthorInvitation
    {
        if (! in_array($invitation->state(), ['pending', 'expired'], true)) {
            throw ValidationException::withMessages(['invitation' => 'Only a pending or expired invitation can be sent again.']);
        }

        if ($invitation->resend_count >= (int) config('growth.invitations.max_resends')) {
            throw ValidationException::withMessages(['invitation' => 'This invitation has been sent again '.$invitation->resend_count.' times already. Revoke it if it should not stand.']);
        }

        $wait = (int) config('growth.invitations.resend_after_minutes');
        $last = $invitation->last_resent_at ?? $invitation->sent_at ?? $invitation->created_at;

        if ($last && $last->gt(now()->subMinutes($wait))) {
            throw ValidationException::withMessages(['invitation' => "Sent less than {$wait} minutes ago. Give it time to arrive."]);
        }

        if (User::whereRaw('lower(email) = ?', [$invitation->email])->exists()) {
            throw ValidationException::withMessages(['invitation' => 'Already a member: this address has an account now.']);
        }

        if ($this->optedOut($invitation->email)) {
            throw ValidationException::withMessages(['invitation' => 'This person asked not to be invited again.']);
        }

        $this->guardDaily($actor, 1);
        $token = Str::random(48);
        $days = (int) config('growth.invitations.default_days');

        try {
            AdminAudit::transaction(function () use ($invitation, $actor, $token, $days) {
                $before = ['status' => $invitation->status, 'expires_at' => $invitation->expires_at->toIso8601String(), 'resend_count' => $invitation->resend_count];

                $invitation->forceFill([
                    'status' => 'pending',
                    'expired_at' => null,
                    'token_hash' => AuthorInvitation::hashToken($token),
                    'last_resent_at' => now(),
                    'resend_count' => $invitation->resend_count + 1,
                    // Never shorter than what it had.
                    'expires_at' => $invitation->expires_at->max(now()->addDays($days)),
                ])->save();

                AdminAudit::record('invitation.resent', $invitation, $before, [
                    'status' => 'pending',
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                    'resend_count' => $invitation->resend_count,
                ], null, $actor);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['invitation' => 'A newer invitation to this address is pending. Resend that one.']);
        }

        $this->send($invitation, $token);

        return $invitation;
    }

    public function revoke(AuthorInvitation $invitation, string $reason, User $actor): void
    {
        if ($invitation->status !== 'pending') {
            throw ValidationException::withMessages(['invitation' => 'Only a pending invitation can be revoked.']);
        }

        AdminAudit::transaction(function () use ($invitation, $reason, $actor) {
            $invitation->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
            AdminAudit::record('invitation.revoked', $invitation, ['status' => 'pending'], ['status' => 'revoked'], $reason, $actor);
        });
    }

    /** "Don't invite me again", from the link in the email. */
    public function decline(AuthorInvitation $invitation): void
    {
        if (! $invitation->isLive()) {
            return;
        }

        AdminAudit::transaction(function () use ($invitation) {
            $invitation->forceFill(['status' => 'declined', 'declined_at' => now()])->save();

            DB::table('author_invitation_opt_outs')->insertOrIgnore([
                'email_hash' => self::emailHash($invitation->email),
                'invitation_id' => $invitation->id,
                'created_at' => now(),
            ]);

            AdminAudit::record('invitation.declined', $invitation, ['status' => 'pending'], ['status' => 'declined'], null, null, system: true);
        });
    }

    /** Pending invitations past their date become expired (scheduled hourly). */
    public function expireDue(): int
    {
        return AdminAudit::transaction(function () {
            $ids = AuthorInvitation::where('status', 'pending')->where('expires_at', '<=', now())->lockForUpdate()->pluck('id')->all();

            if ($ids === []) {
                return 0;
            }

            AuthorInvitation::whereIn('id', $ids)->update(['status' => 'expired', 'expired_at' => now(), 'updated_at' => now()]);
            AdminAudit::record('invitation.expired', null, null, ['count' => count($ids), 'ids' => $ids], 'Expiry date reached', null, system: true);

            return count($ids);
        });
    }

    // ── Accepting ───────────────────────────────────────────────────────

    /**
     * Accept for $user, inside the caller's transaction when there is one
     * (registration creates the account in the same one, so a perk that
     * cannot be applied leaves no account behind).
     *
     * $newAccount: registered through this invitation, so it came from the
     * invitation (users.signup_source = invite). An account that already
     * existed keeps its own first touch.
     */
    public function accept(AuthorInvitation $invitation, User $user, bool $newAccount): AuthorInvitation
    {
        $accepted = AdminAudit::transaction(function () use ($invitation, $user, $newAccount) {
            // The lock makes the token single-use: two tabs, one acceptance.
            $row = AuthorInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if (! $row->isLive()) {
                throw ValidationException::withMessages(['email' => 'This invitation is no longer valid.']);
            }

            if (self::normalizeEmail($user->email) !== $row->email) {
                throw ValidationException::withMessages(['email' => 'This invitation was sent to a different email address.']);
            }

            $applied = $this->applyPerks($row, $user);
            $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()]);

            if ($newAccount) {
                $user->forceFill([
                    'signup_source' => 'invite',
                    'signup_medium' => 'email',
                    'signup_campaign' => $row->campaign ?? ($row->batch_id ? 'batch-'.$row->batch_id : null),
                    'signup_landing' => '/join',
                ]);
            }

            $user->save();

            $row->forceFill([
                'status' => 'accepted',
                'accepted_user_id' => $user->id,
                'accepted_at' => now(),
                'perks_applied' => $applied ?: null,
            ])->save();

            AdminAudit::record('invitation.accepted', $row, ['status' => 'pending'], [
                'status' => 'accepted',
                'user_id' => $user->id,
                'new_account' => $newAccount,
                'perks_applied' => $applied,
            ], null, $user);

            return $row;
        });

        Inbox::record(
            $user,
            'welcome',
            'Welcome to '.config('mail.ecrivain.brand').': your invitation is accepted',
            body: $this->welcomeBody($accepted),
        );

        return $accepted;
    }

    /** @return array<string, mixed> what was given, and what could not be */
    private function applyPerks(AuthorInvitation $row, User $user): array
    {
        $perks = $row->perkList();
        $inviter = $row->invited_by ? User::find($row->invited_by) : null;
        $reason = 'Invitation #'.$row->id;
        $applied = [];

        if ($perks['plan'] && $perks['plan_months']) {
            $plan = $this->plans->get($perks['plan']);

            if ($plan === null || $perks['plan'] === 'free') {
                $applied['plan_skipped'] = 'The plan is no longer in the catalogue.';
            } elseif ($this->accounts->hasActiveSubscription($user)) {
                $applied['plan_skipped'] = 'The account already pays through Stripe.';
            } else {
                $until = now()->addMonths($perks['plan_months']);
                $this->accounts->setComplimentary($user, $perks['plan'], $until, $reason, $inviter);
                $applied['plan'] = $perks['plan'];
                $applied['plan_until'] = $until->toIso8601String();
            }
        }

        if ($perks['credits']) {
            $balance = $this->ledger->balanceFor($user);
            $tx = $this->ledger->grant($user, $perks['credits'], $reason, 'invitation:'.$row->id, 'admin_grant');

            AdminAudit::record('credits.granted', $user, ['balance' => $balance], [
                'balance' => $balance + $tx->amount,
                'amount' => $tx->amount,
                'transaction_id' => $tx->id,
                'invitation_id' => $row->id,
            ], $reason, $inviter, system: $inviter === null);

            $applied['credits'] = $tx->amount;
        }

        return $applied;
    }

    private function welcomeBody(AuthorInvitation $invitation): string
    {
        $applied = $invitation->perks_applied ?? [];
        $lines = [];

        if (isset($applied['plan'])) {
            $name = $this->plans->get($applied['plan'])['name'] ?? ucfirst($applied['plan']);
            $lines[] = "Your {$name} plan is on the house until ".substr($applied['plan_until'], 0, 10).'.';
        }

        if (isset($applied['credits'])) {
            $lines[] = number_format($applied['credits']).' AI credits are in your account.';
        }

        $lines[] = 'Start a book from your shelf, or import a manuscript you already have.';

        return implode(' ', $lines);
    }

    private static function separator(string $line): string
    {
        return str_contains($line, "\t") ? "\t" : (substr_count($line, ';') > substr_count($line, ',') ? ';' : ',');
    }

    private static function stripBom(string $text): string
    {
        return str_starts_with($text, "\u{FEFF}") ? substr($text, 3) : $text;
    }
}
