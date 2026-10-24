<?php

namespace App\Platform\Trust;

use App\Mail\Notify;
use App\Models\AccountDeletion;
use App\Models\Book;
use App\Models\User;
use App\Platform\AccountAdmin;
use App\Platform\AdminAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Deleting an account, after Credentik's offboarding: a dry run that lists
 * exactly what will go, a grace period, a typed confirmation, and a
 * destruction certificate.
 *
 *  1. Dry run (plan): what is deleted, what is anonymised, what is kept and
 *     why, the files on disk, and anything that refuses it.
 *  2. Schedule: owner, reason, the account's email typed. The account is
 *     suspended (signed out, cannot sign in) for GRACE_DAYS and nothing is
 *     deleted; the dry run is stored with the request.
 *  3. Cancel, any time before execution: the suspension is lifted if
 *     scheduling put it there.
 *  4. Execute, after the grace period, owner, email typed again. One
 *     transaction deletes and anonymises the rows; then the files go; then
 *     the certificate is written — counts, files, who and when, a SHA-256
 *     over its canonical JSON — kept on the request row and as JSON + HTML
 *     on the files disk. The account is told at its old address.
 *
 * The user row is not deleted: it is emptied (name, email, password,
 * profile, Stripe and two-factor fields) and marked offboarded. Sales other
 * people paid for name it as seller (order_items.seller_id cascades on a
 * user delete), so deleting the row would delete buyers' purchase records
 * and their licences with them. Orders the account placed as a buyer keep
 * their amounts and lose the buyer's name and address.
 *
 * Refused for platform staff (demote first, from the shell), for an account
 * with an active Stripe subscription (cancel it in Stripe first: a
 * subscription nobody can see would go on charging), and twice.
 */
final class Offboarding
{
    public const GRACE_DAYS = 7;

    public function __construct(private readonly AccountAdmin $accounts) {}

    /**
     * The dry run. Reads only.
     *
     * @return array<string, mixed>
     */
    public function plan(User $user): array
    {
        $bookIds = Book::withTrashed()->where('user_id', $user->id)->pluck('id')->all();
        $sectionIds = DB::table('manuscript_sections')->whereIn('book_id', $bookIds)->pluck('id')->all();
        $count = fn (string $table, string $column, array $ids) => $ids === [] ? 0 : DB::table($table)->whereIn($column, $ids)->count();

        $delete = [
            'books' => count($bookIds),
            'manuscript_sections' => count($sectionIds),
            'manuscript_versions' => $count('manuscript_versions', 'manuscript_section_id', $sectionIds),
            'comments_on_their_books' => $count('comments', 'manuscript_section_id', $sectionIds),
            'editorial_reviews' => $count('editorial_reviews', 'manuscript_section_id', $sectionIds),
            'editorial_findings' => $count('editorial_findings', 'manuscript_section_id', $sectionIds),
            'citations' => $count('citations', 'manuscript_section_id', $sectionIds),
            'research_sources' => $count('research_sources', 'book_id', $bookIds),
            'terminology' => $count('terminology', 'book_id', $bookIds),
            'book_exports' => $count('book_exports', 'book_id', $bookIds),
            'book_channels' => $count('book_channels', 'book_id', $bookIds),
            'products' => DB::table('products')->where('user_id', $user->id)->count(),
            'book_members' => $count('book_members', 'book_id', $bookIds) + DB::table('book_members')->where('user_id', $user->id)->whereNotIn('book_id', $bookIds ?: [0])->count(),
            'isbns' => DB::table('isbns')->where('user_id', $user->id)->count(),
            'inbox_items' => DB::table('inbox_items')->where('user_id', $user->id)->count(),
            'support_tickets' => DB::table('support_tickets')->where('user_id', $user->id)->count(),
            'user_logins' => DB::table('user_logins')->where('user_id', $user->id)->count(),
            'login_failures' => DB::table('login_failures')->where('user_id', $user->id)->count(),
            'feature_overrides' => DB::table('feature_overrides')->where('user_id', $user->id)->count(),
            'admin_notes' => DB::table('admin_notes')->where('user_id', $user->id)->count(),
            'sessions' => DB::table('sessions')->where('user_id', $user->id)->count(),
            'subscriptions' => DB::table('subscriptions')->where('user_id', $user->id)->count(),
            'account_exports' => DB::table('account_exports')->where('user_id', $user->id)->count(),
        ];

        $anonymise = [
            'users' => 1,
            'orders_as_buyer' => DB::table('orders')->where('buyer_user_id', $user->id)->count(),
            'comments_on_other_books' => DB::table('comments')->where('user_id', $user->id)->whereNotIn('manuscript_section_id', $sectionIds ?: [0])->count(),
            'mail_logs' => DB::table('mail_logs')->where('user_id', $user->id)->orWhere('to', $user->email)->count(),
            'ai_usage' => DB::table('ai_usage')->where('user_id', $user->id)->count(),
            'content_reports_filed' => DB::table('content_reports')->where('reporter_user_id', $user->id)->count(),
        ];

        $soldItems = DB::table('order_items')->where('seller_id', $user->id)->count();
        $activeLicences = DB::table('licenses')
            ->join('order_items', 'order_items.id', '=', 'licenses.order_item_id')
            ->where('order_items.seller_id', $user->id)
            ->where('licenses.status', 'active')
            ->count();

        $retain = [
            'order_items_as_seller' => ['rows' => $soldItems, 'why' => 'Buyers\' purchase records and accounting. No personal data of this account in them.'],
            'credit_transactions' => ['rows' => DB::table('credit_transactions')->where('user_id', $user->id)->count(), 'why' => 'The credit ledger is append-only accounting; it points at the emptied account row.'],
            'royalty_statements' => ['rows' => DB::table('royalty_statements')->where('user_id', $user->id)->count(), 'why' => 'Statements already sent are accounting records.'],
            'admin_audit_logs' => ['rows' => DB::table('admin_audit_logs')->where('subject_type', 'User')->where('subject_id', $user->id)->count(), 'why' => 'The audit log is append-only.'],
            'impersonation_sessions' => ['rows' => DB::table('impersonation_sessions')->where('target_id', $user->id)->count(), 'why' => 'Part of the audit trail.'],
        ];

        $files = $this->files($user, $bookIds);

        $refusals = [];

        if ($user->isPlatformAdmin()) {
            $refusals[] = 'This is a platform staff account. Remove the role from the shell (php artisan platform:demote) first.';
        }

        if ($this->accounts->hasActiveSubscription($user)) {
            $refusals[] = 'This account has an active paid subscription. Cancel it in Stripe first: a subscription nobody can see would go on charging.';
        }

        if ($user->offboarded_at) {
            $refusals[] = 'This account has already been deleted.';
        }

        $warnings = [];

        if ($activeLicences > 0) {
            $warnings[] = "{$activeLicences} licence(s) sold from this account stay on record, but their downloads stop: the books they were for are deleted.";
        }

        return [
            'account' => ['id' => $user->id, 'email' => $user->email, 'name' => $user->name],
            'delete' => $delete,
            'anonymise' => $anonymise,
            'retain' => $retain,
            'files' => ['count' => count($files['paths']), 'bytes' => $files['bytes'], 'sample' => array_slice($files['paths'], 0, 20), 'measured' => $files['measured']],
            'refusals' => $refusals,
            'warnings' => $warnings,
            'grace_days' => self::GRACE_DAYS,
            'planned_at' => now()->toIso8601String(),
        ];
    }

    public function schedule(User $user, string $reason, string $typedEmail, User $actor): AccountDeletion
    {
        $this->confirm($user, $typedEmail);
        $plan = $this->plan($user);
        $this->refuse($plan);

        if (AccountDeletion::where('user_id', $user->id)->where('status', 'scheduled')->exists()) {
            throw ValidationException::withMessages(['reason' => 'A deletion is already scheduled for this account.']);
        }

        $deletion = AdminAudit::transaction(function () use ($user, $reason, $actor, $plan) {
            $wasSuspended = $user->isSuspended();
            $when = now()->addDays(self::GRACE_DAYS);

            $deletion = AccountDeletion::create([
                'user_id' => $user->id,
                'email_hash' => SignInEvents::emailHash($user->email),
                'status' => 'scheduled',
                'reason' => trim($reason),
                'requested_by' => $actor->id,
                // Without the address: this row outlives the account.
                'plan' => array_replace($plan, ['account' => ['id' => $user->id]]),
                'scheduled_for' => $when,
                'suspended_by_us' => ! $wasSuspended,
            ]);

            if (! $wasSuspended) {
                $user->forceFill(['suspended_at' => now(), 'suspended_reason' => 'Scheduled for deletion on '.$when->toFormattedDateString().'.'])->save();
            }

            $this->accounts->revokeSessions($user);

            AdminAudit::record('account.deletion_scheduled', $user, null, [
                'deletion_id' => $deletion->id,
                'scheduled_for' => $when->toIso8601String(),
                'books' => $plan['delete']['books'],
                'files' => $plan['files']['count'],
            ], $reason, $actor);

            return $deletion;
        });

        return $deletion;
    }

    public function cancel(AccountDeletion $deletion, string $reason, User $actor): void
    {
        if ($deletion->status !== 'scheduled') {
            throw ValidationException::withMessages(['reason' => 'Only a scheduled deletion can be cancelled.']);
        }

        AdminAudit::transaction(function () use ($deletion, $reason, $actor) {
            $deletion->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => trim($reason),
            ])->save();

            $user = $deletion->user;

            if ($user && $deletion->suspended_by_us && $user->isSuspended()) {
                $user->forceFill(['suspended_at' => null, 'suspended_reason' => null])->save();
            }

            AdminAudit::record('account.deletion_cancelled', $user ?? $deletion, ['status' => 'scheduled'], ['status' => 'cancelled', 'deletion_id' => $deletion->id], $reason, $actor);
        });
    }

    /** Delete it. Returns the completed request with its certificate. */
    public function execute(AccountDeletion $deletion, string $typedEmail, User $actor): AccountDeletion
    {
        $user = $deletion->user;

        if ($deletion->status !== 'scheduled' || ! $user) {
            throw ValidationException::withMessages(['confirm_email' => 'Only a scheduled deletion can be carried out.']);
        }

        if (! $deletion->graceOver()) {
            throw ValidationException::withMessages(['confirm_email' => 'The grace period runs until '.$deletion->scheduled_for->toDayDateTimeString().'. It can be cancelled until then, not hurried.']);
        }

        $this->confirm($user, $typedEmail);
        $plan = $this->plan($user);
        $this->refuse($plan);

        $farewell = [$user->name, $user->email, $plan['delete']['books']];
        $bookIds = Book::withTrashed()->where('user_id', $user->id)->pluck('id')->all();
        $files = $this->files($user, $bookIds);
        $started = now();

        $counts = AdminAudit::transaction(function () use ($user, $bookIds, $deletion, $actor) {
            $counts = ['deleted' => [], 'anonymised' => []];
            $sectionIds = DB::table('manuscript_sections')->whereIn('book_id', $bookIds)->pluck('id')->all();
            $email = $user->email;

            // Anonymised first: some of these rows would otherwise go with
            // the books (comments on their own books do; on others' do not).
            $counts['anonymised']['comments_on_other_books'] = DB::table('comments')->where('user_id', $user->id)
                ->whereNotIn('manuscript_section_id', $sectionIds ?: [0])
                ->update(['user_id' => null, 'author_name' => 'Deleted account']);
            $counts['anonymised']['orders_as_buyer'] = DB::table('orders')->where('buyer_user_id', $user->id)
                ->update(['buyer_user_id' => null, 'buyer_email' => null, 'buyer_name' => null, 'buyer_country' => null]);
            $counts['anonymised']['mail_logs'] = DB::table('mail_logs')->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('to', $email))
                ->update(['user_id' => null, 'to' => '[deleted]', 'body_text' => null, 'resend_args' => null]);
            $counts['anonymised']['ai_usage'] = DB::table('ai_usage')->where('user_id', $user->id)->update(['user_id' => null]);
            $counts['anonymised']['content_reports_filed'] = DB::table('content_reports')->where('reporter_user_id', $user->id)
                ->update(['reporter_user_id' => null, 'reporter_email' => null]);

            // Books go for good (not to the bin); sections, versions,
            // comments, reviews, findings, citations, sources, terms,
            // exports, channels, members and the product cascade with them.
            $counts['deleted']['books'] = DB::table('books')->whereIn('id', $bookIds)->delete();

            foreach ([
                'book_members' => 'user_id', 'isbns' => 'user_id', 'inbox_items' => 'user_id',
                'support_tickets' => 'user_id', 'user_logins' => 'user_id', 'login_failures' => 'user_id',
                'feature_overrides' => 'user_id', 'admin_notes' => 'user_id', 'sessions' => 'user_id',
                'subscriptions' => 'user_id', 'products' => 'user_id',
            ] as $table => $column) {
                $counts['deleted'][$table] = DB::table($table)->where($column, $user->id)->delete();
            }

            DB::table('account_exports')->where('user_id', $user->id)->update(['status' => 'expired', 'path' => null, 'updated_at' => now()]);

            // The row stays, emptied: see the class comment.
            $user->forceFill([
                'name' => 'Deleted account',
                'email' => "deleted-{$user->id}-".Str::lower(Str::random(10)).'@deleted.invalid',
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'email_verified_at' => null,
                'author_name' => null,
                'bio' => null,
                'website' => null,
                'store_handle' => null,
                'stripe_id' => null,
                'pm_type' => null,
                'pm_last_four' => null,
                'email_preferences' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'two_factor_last_step' => null,
                'suspended_at' => $user->suspended_at ?? now(),
                'suspended_reason' => 'Deleted.',
                'sessions_revoked_at' => now(),
                'offboarded_at' => now(),
            ])->save();
            $counts['anonymised']['users'] = 1;

            $deletion->forceFill(['status' => 'completed', 'completed_at' => now(), 'executed_by' => $actor->id])->save();

            AdminAudit::record('account.deleted', $user, null, ['deletion_id' => $deletion->id, 'books' => $counts['deleted']['books']], $deletion->reason, $actor);

            return $counts;
        });

        // After the commit: a file deleted for an account whose deletion
        // rolled back could not be put back.
        $removed = 0;
        $failed = [];

        foreach ($files['paths'] as $path) {
            try {
                Storage::disk('files')->delete($path) ? $removed++ : $failed[] = $path;
            } catch (Throwable $e) {
                report($e);
                $failed[] = $path;
            }
        }

        $certificate = $this->certificate($deletion->fresh(['requester']), $plan, $counts, $removed, $failed, $started, $actor);

        Notify::accountDeleted(...$farewell);

        return $certificate;
    }

    /** @return array{paths: list<string>, bytes: ?int, measured: string} */
    private function files(User $user, array $bookIds): array
    {
        $disk = Storage::disk('files');
        $paths = [];
        $bytes = 0;

        $dirs = ["account-exports/user-{$user->id}"];

        foreach ($bookIds as $id) {
            array_push($dirs, "exports/book-{$id}", "covers/book-{$id}", "images/book-{$id}");
        }

        foreach ($dirs as $dir) {
            try {
                foreach ($disk->allFiles($dir) as $file) {
                    $paths[] = $file;

                    if ($bytes !== null) {
                        try {
                            $bytes += (int) $disk->size($file);
                        } catch (Throwable) {
                            $bytes = null;
                        }
                    }
                }
            } catch (Throwable) {
                // A directory that does not exist has nothing to delete.
            }
        }

        return ['paths' => $paths, 'bytes' => $bytes, 'measured' => 'Listed from the files disk ('.config('filesystems.disks.files.driver').'): exports, cover pictures and images of each book, and account exports.'];
    }

    private function confirm(User $user, string $typed): void
    {
        if (mb_strtolower(trim($typed)) !== mb_strtolower($user->email)) {
            throw ValidationException::withMessages(['confirm_email' => 'Type the account\'s email address exactly to confirm.']);
        }
    }

    private function refuse(array $plan): void
    {
        if ($plan['refusals'] !== []) {
            throw ValidationException::withMessages(['confirm_email' => $plan['refusals'][0]]);
        }
    }

    /**
     * The certificate: what went, what stayed and why, who and when, and a
     * SHA-256 over its canonical JSON (keys sorted, no whitespace) so a copy
     * can be checked against the one stored.
     */
    private function certificate(AccountDeletion $deletion, array $plan, array $counts, int $removed, array $failed, $started, User $actor): AccountDeletion
    {
        $body = [
            'certificate_id' => (string) Str::uuid(),
            'title' => 'Certificate of account deletion',
            'issued_at' => now()->toIso8601String(),
            'account' => ['id' => $deletion->user_id, 'email_sha256_hmac' => $deletion->email_hash],
            'request' => [
                'id' => $deletion->id,
                'reason' => $deletion->reason,
                'requested_by' => $deletion->requested_by,
                'requested_at' => $deletion->created_at?->toIso8601String(),
                'grace_days' => self::GRACE_DAYS,
                'scheduled_for' => $deletion->scheduled_for?->toIso8601String(),
            ],
            'executed' => ['by' => $actor->id, 'started_at' => $started->toIso8601String(), 'completed_at' => now()->toIso8601String()],
            'deleted' => $counts['deleted'],
            'cascaded_with_books' => array_intersect_key($plan['delete'], array_flip([
                'manuscript_sections', 'manuscript_versions', 'comments_on_their_books', 'editorial_reviews', 'editorial_findings',
                'citations', 'research_sources', 'terminology', 'book_exports', 'book_channels',
            ])),
            'anonymised' => $counts['anonymised'],
            'retained' => $plan['retain'],
            'files' => ['deleted' => $removed, 'failed' => $failed],
        ];

        $canonical = self::canonical($body);
        $hash = hash('sha256', $canonical);
        $base = "certificates/account-deletion-{$deletion->id}";

        try {
            Storage::disk('files')->put("{$base}.json", json_encode($body + ['sha256' => $hash], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            Storage::disk('files')->put("{$base}.html", view('platform.deletion-certificate', ['c' => $body, 'sha256' => $hash])->render());
            $path = "{$base}.html";
        } catch (Throwable $e) {
            // The certificate is also on the row; a storage failure must not
            // lose it.
            report($e);
            $path = null;
        }

        $deletion->forceFill(['certificate' => $body, 'certificate_sha256' => $hash, 'certificate_path' => $path])->save();

        return $deletion;
    }

    public static function canonical(array $data): string
    {
        $sort = function ($value) use (&$sort) {
            if (is_array($value)) {
                if (! array_is_list($value)) {
                    ksort($value);
                }

                return array_map($sort, $value);
            }

            return $value;
        };

        return json_encode($sort($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
