<?php

namespace App\Mail;

use App\Editorial\Finding;
use App\Mail\Account\AccountDeletedMail;
use App\Mail\Account\EmailChangedMail;
use App\Mail\Account\NewSignInMail;
use App\Mail\Account\PasswordChangedMail;
use App\Mail\Account\WelcomeMail;
use App\Mail\Billing\CreditsLowMail;
use App\Mail\Billing\PaymentFailedMail;
use App\Mail\Billing\PlanChangedMail;
use App\Mail\Billing\SubscriptionCancelledMail;
use App\Mail\Billing\SubscriptionReceiptMail;
use App\Mail\Billing\TrialEndingMail;
use App\Mail\Collaboration\BookInvitationMail;
use App\Mail\Collaboration\CommentMentionMail;
use App\Mail\Collaboration\CommentReplyMail;
use App\Mail\Collaboration\InvitationAcceptedMail;
use App\Mail\Collaboration\ReviewerAssignedMail;
use App\Mail\Commerce\NewSaleMail;
use App\Mail\Commerce\OrderReceiptMail;
use App\Mail\Commerce\PaperbackShippedMail;
use App\Mail\Commerce\RefundProcessedMail;
use App\Mail\Commerce\RoyaltyStatementMail;
use App\Mail\Publishing\BookLiveMail;
use App\Mail\Publishing\BookUnpublishedMail;
use App\Mail\Publishing\ExportFailedMail;
use App\Mail\Publishing\ExportReadyMail;
use App\Mail\Publishing\SubmittedToStoresMail;
use App\Mail\Writing\BookDirectorReportMail;
use App\Mail\Writing\EditorialReviewCompleteMail;
use App\Models\Book;
use App\Models\BookChannel;
use App\Models\BookExport;
use App\Models\BookMember;
use App\Models\Comment;
use App\Models\EditorialReview;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Platform\House\House;
use App\Platform\Ops\MailLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The one way the application sends email.
 *
 * Every call site says what happened — Notify::exportReady($export) — and
 * this class decides who hears about it, checks their email preferences,
 * and hands the Mailable to the mailer (queued, unless the Mailable is one
 * of the two that must arrive immediately).
 *
 * Sending never breaks the action that caused it. A comment is still saved
 * when the mail transport is down; the failure is reported, not thrown.
 * Every method returns whether a message was handed to the mailer.
 *
 * Nothing here is wired to the export pipeline, publishing or Stripe yet —
 * those are being rebuilt in other branches. docs/EMAILS.md lists the exact
 * call to add at each site after merge.
 */
final class Notify
{
    /**
     * Send one email, honouring preferences.
     *
     * @param  User|string  $to  an account, or a bare address for people without one
     */
    public static function send(User|string $to, EcrivainMail $mail): bool
    {
        $account = $to instanceof User ? $to : $mail->account();

        // A house publisher account is the platform's own imprint: nobody
        // reads its mail (HouseMailGuard repeats this on the mailer).
        if ($account?->isHouse() || House::isHouseAddress($to instanceof User ? $to->email : $to)) {
            return false;
        }

        if (! EmailPreferences::allows($account, $mail->category())) {
            return false;
        }

        $address = $to instanceof User ? $to->email : $to;

        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            Mail::to($address)->send($mail);

            return true;
        } catch (Throwable $e) {
            // In the email log as failed: a mail that never left is the one
            // support most needs to find.
            MailLogger::failed($mail, $address, $e);

            // Under test the queue is sync and the mailer is "array", so a
            // template that throws surfaces here. Swallowing it would hide
            // exactly the regression the suite is there to catch.
            if (app()->runningUnitTests()) {
                throw $e;
            }

            report($e);
            Log::warning('Email not sent', ['email' => $mail::slug(), 'error' => $e->getMessage()]);

            return false;
        }
    }

    // ── Account ─────────────────────────────────────────────────────────

    public static function welcome(User $user): bool
    {
        return self::send($user, new WelcomeMail($user));
    }

    public static function passwordChanged(User $user, ?string $ipAddress = null): bool
    {
        return self::send($user, new PasswordChangedMail($user, now(), $ipAddress));
    }

    /** Goes to the OLD address — see EmailChangedMail. */
    public static function emailChanged(User $user, string $oldEmail): bool
    {
        return self::send($oldEmail, new EmailChangedMail($user, $oldEmail, $user->email, now()));
    }

    public static function newSignIn(User $user, string $ipAddress, ?string $userAgent, ?string $location = null): bool
    {
        return self::send($user, new NewSignInMail($user, $ipAddress, $userAgent, now(), $location));
    }

    public static function accountDeleted(string $name, string $email, int $booksDeleted): bool
    {
        return self::send($email, new AccountDeletedMail($name, $email, now(), $booksDeleted));
    }

    // ── Collaboration ───────────────────────────────────────────────────

    public static function bookInvitation(BookMember $member): bool
    {
        return self::send($member->email, new BookInvitationMail($member));
    }

    public static function invitationAccepted(BookMember $member): bool
    {
        $owner = $member->book->user;

        return $owner ? self::send($owner, new InvitationAcceptedMail($member)) : false;
    }

    /**
     * A comment was posted: tell whoever was @mentioned, and — for a reply —
     * everybody else in the thread. Nobody hears about their own comment,
     * and nobody gets both emails for one comment (a mention wins).
     *
     * @return int how many emails were sent
     */
    public static function commentPosted(Comment $comment): int
    {
        $book = $comment->section->book;
        $sent = 0;
        $told = [$comment->user_id => true];

        foreach (self::mentioned($comment, $book) as $user) {
            if (! isset($told[$user->id])) {
                $told[$user->id] = true;
                $sent += (int) self::send($user, new CommentMentionMail($comment, $user));
            }
        }

        if ($comment->parent_id) {
            $thread = $comment->parent;
            $participants = collect([$thread->user_id])
                ->merge($thread->replies()->pluck('user_id'))
                ->filter()
                ->unique();

            foreach (User::whereIn('id', $participants)->get() as $user) {
                // Only people who can still open the book.
                if (! isset($told[$user->id]) && $user->can('view', $book)) {
                    $told[$user->id] = true;
                    $sent += (int) self::send($user, new CommentReplyMail($comment, $user));
                }
            }
        }

        return $sent;
    }

    public static function reviewerAssigned(ManuscriptSection $section, User $reviewer, ?User $assignedBy = null): bool
    {
        if ($assignedBy && $assignedBy->is($reviewer)) {
            return false;
        }

        return self::send($reviewer, new ReviewerAssignedMail($section, $reviewer, $assignedBy));
    }

    // ── Writing ─────────────────────────────────────────────────────────

    public static function editorialReviewComplete(EditorialReview $review): bool
    {
        $recipient = User::find($review->user_id) ?? $review->section->book->user;

        return $recipient ? self::send($recipient, new EditorialReviewCompleteMail($review, $recipient)) : false;
    }

    /** @param  list<Finding>  $findings */
    public static function bookDirectorReport(Book $book, array $findings, ?User $recipient = null): bool
    {
        $recipient ??= $book->user;

        return $recipient ? self::send($recipient, BookDirectorReportMail::fromFindings($book, $recipient, $findings)) : false;
    }

    // ── Exports and publishing ──────────────────────────────────────────

    public static function exportReady(BookExport $export): bool
    {
        $recipient = self::exportRecipient($export);

        return $recipient ? self::send($recipient, new ExportReadyMail($export, $recipient)) : false;
    }

    public static function exportFailed(BookExport $export, ?string $reason = null): bool
    {
        $recipient = self::exportRecipient($export);

        return $recipient ? self::send($recipient, new ExportFailedMail($export, $recipient, $reason)) : false;
    }

    /** @param  list<string>  $channels  BookChannel::CHANNELS keys */
    public static function submittedToStores(Book $book, array $channels, ?User $recipient = null): bool
    {
        $recipient ??= $book->user;

        return $recipient && $channels !== []
            ? self::send($recipient, new SubmittedToStoresMail($book, $recipient, array_values($channels)))
            : false;
    }

    public static function bookLive(BookChannel $channel, ?string $storeUrl = null): bool
    {
        $recipient = $channel->book->user;

        return $recipient ? self::send($recipient, new BookLiveMail($channel, $recipient, $storeUrl)) : false;
    }

    public static function bookUnpublished(BookChannel $channel, ?string $reason = null, bool $byAuthor = true): bool
    {
        $recipient = $channel->book->user;

        return $recipient ? self::send($recipient, new BookUnpublishedMail($channel, $recipient, $reason, $byAuthor)) : false;
    }

    // ── Billing ─────────────────────────────────────────────────────────
    // Arguments after $user are the Mailable's own constructor arguments,
    // passed by name: Notify::paymentFailed($user, planName: 'Author', …).

    public static function subscriptionReceipt(User $user, mixed ...$details): bool
    {
        return self::send($user, new SubscriptionReceiptMail($user, ...$details));
    }

    public static function paymentFailed(User $user, mixed ...$details): bool
    {
        return self::send($user, new PaymentFailedMail($user, ...$details));
    }

    public static function planChanged(User $user, mixed ...$details): bool
    {
        return self::send($user, new PlanChangedMail($user, ...$details));
    }

    public static function subscriptionCancelled(User $user, string $planName, CarbonInterface $endsAt): bool
    {
        return self::send($user, new SubscriptionCancelledMail($user, $planName, $endsAt));
    }

    public static function trialEnding(User $user, mixed ...$details): bool
    {
        return self::send($user, new TrialEndingMail($user, ...$details));
    }

    public static function creditsLow(User $user, int $balance, int $monthlyAllowance): bool
    {
        return self::send($user, new CreditsLowMail($user, $balance, $monthlyAllowance));
    }

    // ── Commerce ────────────────────────────────────────────────────────

    public static function orderReceipt(string $buyerEmail, mixed ...$details): bool
    {
        return self::send($buyerEmail, new OrderReceiptMail($buyerEmail, ...$details));
    }

    public static function newSale(User $author, mixed ...$details): bool
    {
        return self::send($author, new NewSaleMail($author, ...$details));
    }

    public static function refundProcessed(string $buyerEmail, mixed ...$details): bool
    {
        return self::send($buyerEmail, new RefundProcessedMail($buyerEmail, ...$details));
    }

    /** A storefront paperback left the printer (App\Commerce\Print\PrintJobs). */
    public static function paperbackShipped(string $buyerEmail, mixed ...$details): bool
    {
        return self::send($buyerEmail, new PaperbackShippedMail($buyerEmail, ...$details));
    }

    public static function royaltyStatement(User $author, mixed ...$details): bool
    {
        return self::send($author, new RoyaltyStatementMail($author, ...$details));
    }

    // ── Internals ───────────────────────────────────────────────────────

    private static function exportRecipient(BookExport $export): ?User
    {
        return ($export->user_id ? User::find($export->user_id) : null) ?? $export->book?->user;
    }

    /**
     * People named as @Full Name (or @first-name, when that is unambiguous)
     * who can open the book: the owner and its active members.
     *
     * @return list<User>
     */
    public static function mentioned(Comment $comment, Book $book): array
    {
        if (! str_contains($comment->body, '@')) {
            return [];
        }

        $people = collect([$book->user])
            ->merge(User::whereIn('id', $book->members()->where('status', 'active')->whereNotNull('user_id')->pluck('user_id'))->get())
            ->filter()
            ->unique('id');

        $body = mb_strtolower($comment->body);
        $firstNames = $people->countBy(fn (User $u) => mb_strtolower(explode(' ', trim($u->name))[0]));

        return $people->filter(function (User $user) use ($body, $firstNames) {
            $full = mb_strtolower(trim($user->name));
            $first = mb_strtolower(explode(' ', $full)[0]);

            return self::containsHandle($body, $full)
                || ($firstNames[$first] === 1 && self::containsHandle($body, $first));
        })->values()->all();
    }

    /** "@ada" matches "@ada," and "@ada." but not "@adam". */
    private static function containsHandle(string $body, string $name): bool
    {
        if ($name === '') {
            return false;
        }

        return (bool) preg_match('/@'.preg_quote($name, '/').'(?![\p{L}\p{N}])/u', $body);
    }
}
