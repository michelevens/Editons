<?php

namespace App\Mail\Support;

use App\Editorial\EditorialAgent;
use App\Mail\Account\AccountDeletedMail;
use App\Mail\Account\EmailChangedMail;
use App\Mail\Account\NewSignInMail;
use App\Mail\Account\PasswordChangedMail;
use App\Mail\Account\PasswordResetMail;
use App\Mail\Account\SupportReplyMail;
use App\Mail\Account\VerifyEmailMail;
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
use App\Mail\Commerce\RefundProcessedMail;
use App\Mail\Commerce\RoyaltyStatementMail;
use App\Mail\EcrivainMail;
use App\Mail\Publishing\BookLiveMail;
use App\Mail\Publishing\BookUnpublishedMail;
use App\Mail\Publishing\ExportFailedMail;
use App\Mail\Publishing\ExportReadyMail;
use App\Mail\Publishing\SubmittedToStoresMail;
use App\Mail\Trust\AccountExportReadyMail;
use App\Mail\Trust\ListingRestoredMail;
use App\Mail\Trust\ListingTakenDownMail;
use App\Mail\Writing\BookDirectorReportMail;
use App\Mail\Writing\EditorialReviewCompleteMail;
use App\Mail\Writing\WeeklyDigestMail;
use App\Models\Book;
use App\Models\BookChannel;
use App\Models\BookExport;
use App\Models\BookMember;
use App\Models\Comment;
use App\Models\EditorialFinding;
use App\Models\EditorialReview;
use App\Models\ManuscriptSection;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;

/**
 * Every email, with realistic sample data.
 *
 * One list for three jobs: the /dev/emails preview gallery, the render tests
 * (every entry is rendered as HTML and text, with a hostile book title), and
 * the check that no Mailable ships without a preview. Nothing here touches
 * the database: the sample models are built in memory and never saved.
 */
final class EmailCatalog
{
    /**
     * @return array<string, array{group: string, label: string, class: class-string<EcrivainMail>, trigger: string, make: Closure(?string): EcrivainMail}>
     */
    public static function all(): array
    {
        $entries = [
            // Account
            [WelcomeMail::class, 'Account', 'Welcome', 'Wired: Registered event (MailServiceProvider)',
                fn (array $s) => new WelcomeMail($s['author'])],
            [VerifyEmailMail::class, 'Account', 'Verify email', 'Wired: User::sendEmailVerificationNotification (when MustVerifyEmail is enabled)',
                fn (array $s) => new VerifyEmailMail($s['author'], url('/verify-email/1/3f9c2a?expires=1761400000&signature=4be1c0'), 60)],
            [PasswordResetMail::class, 'Account', 'Password reset', 'Wired: User::sendPasswordResetNotification (immediate)',
                fn (array $s) => new PasswordResetMail($s['author'], url('/reset-password/8c1d7e4b9a?email=amelie%40example.com'), (int) config('auth.passwords.users.expire', 60))],
            [PasswordChangedMail::class, 'Account', 'Password changed', 'Wired: PasswordController::update + PasswordReset event',
                fn (array $s) => new PasswordChangedMail($s['author'], Carbon::parse('2026-10-21 09:14'), '203.0.113.24')],
            [EmailChangedMail::class, 'Account', 'Email address changed', 'Wired: ProfileController::update (to the old address)',
                fn (array $s) => new EmailChangedMail($s['author'], 'amelie.old@example.com', 'amelie@example.com', Carbon::parse('2026-10-21 09:20'))],
            [NewSignInMail::class, 'Account', 'New sign-in', 'Template only: no known-device tracking yet',
                fn (array $s) => new NewSignInMail($s['author'], '198.51.100.7', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', Carbon::parse('2026-10-21 22:41'), 'Montréal, Canada')],
            [AccountDeletedMail::class, 'Account', 'Account deleted', 'Wired: ProfileController::destroy',
                fn (array $s) => new AccountDeletedMail('Amélie Laurent', 'amelie@example.com', Carbon::parse('2026-10-21'), 3)],
            [SupportReplyMail::class, 'Account', 'Support reply', 'Wired: Admin\\SupportController::reply',
                fn (array $s) => new SupportReplyMail($s['ticket'], $s['ticketReply'])],
            [AccountExportReadyMail::class, 'Account', 'Data export ready', 'Wired: App\Platform\Trust\AccountExporter (when staff ask to email it)',
                fn (array $s) => new AccountExportReadyMail($s['author'], url('/account-exports/7/download?expires=1761400000&signature=4be1c0'), Carbon::parse('2026-11-01'), 3)],
            [ListingTakenDownMail::class, 'Account', 'Store listing taken down', 'Wired: App\Platform\Trust\Moderation::takeDown',
                fn (array $s) => new ListingTakenDownMail($s['author'], $s['book']->title, 'The cover reproduces a photograph the author does not hold the rights to.')],
            [ListingRestoredMail::class, 'Account', 'Store listing restored', 'Wired: App\Platform\Trust\Moderation::restore',
                fn (array $s) => new ListingRestoredMail($s['author'], $s['book']->title, 'The photographer confirmed the licence.')],

            // Collaboration
            [BookInvitationMail::class, 'Collaboration', 'Invitation to a book', 'Wired: TeamController::invite / resend',
                fn (array $s) => new BookInvitationMail($s['member'])],
            [InvitationAcceptedMail::class, 'Collaboration', 'Invitation accepted', 'Wired: InvitationController::accept (to the owner)',
                fn (array $s) => new InvitationAcceptedMail($s['acceptedMember'])],
            [CommentMentionMail::class, 'Collaboration', 'Mentioned in a comment', 'Wired: CommentController::store (@Name)',
                fn (array $s) => new CommentMentionMail($s['comment'], $s['reviewer'])],
            [CommentReplyMail::class, 'Collaboration', 'Reply to a comment', 'Wired: CommentController::store (thread participants)',
                fn (array $s) => new CommentReplyMail($s['reply'], $s['reviewer'])],
            [ReviewerAssignedMail::class, 'Collaboration', 'Reviewer assigned', 'Wired: WorkflowController::assign',
                fn (array $s) => new ReviewerAssignedMail($s['section'], $s['reviewer'], $s['author'])],

            // Writing
            [EditorialReviewCompleteMail::class, 'Writing & AI', 'Editorial review complete', 'Helper: Notify::editorialReviewComplete (reviews run synchronously today)',
                fn (array $s) => new EditorialReviewCompleteMail($s['review'], $s['author'])],
            [BookDirectorReportMail::class, 'Writing & AI', 'Book Director report', 'Helper: Notify::bookDirectorReport (report runs synchronously today)',
                fn (array $s) => new BookDirectorReportMail($s['book'], $s['author'], [
                    ['severity' => 'critical', 'title' => 'Part Two carries 46% of the book', 'detail' => 'Chapters 6–11 hold nearly half the words while Part Three has two short chapters. Readers feel the ending arrive in a rush.'],
                    ['severity' => 'warning', 'title' => 'The grandmother disappears after chapter 4', 'detail' => 'Grann Odette drives the first act and is not mentioned again until the epilogue.'],
                    ['severity' => 'warning', 'title' => 'Two chapters open the same way', 'detail' => 'Chapters 3 and 8 both begin at dawn at the market. Consider changing one entry point.'],
                    ['severity' => 'suggestion', 'title' => 'The title promise lands late', 'detail' => 'The salt road itself first appears in chapter 7.'],
                ])],
            [WeeklyDigestMail::class, 'Writing & AI', 'Weekly writing digest', 'Command: php artisan emails:weekly-digest (not scheduled)',
                fn (array $s) => new WeeklyDigestMail($s['author'], Carbon::parse('2026-10-12'), Carbon::parse('2026-10-18'), [
                    'words' => 6240,
                    'active_days' => 5,
                    'chapters_touched' => 4,
                    'open_findings' => 7,
                    'open_comments' => 2,
                    'books' => [
                        ['id' => 42, 'title' => $s['book']->title, 'words_added' => 5180, 'total_words' => 48210, 'target_words' => 80000, 'url' => route('books.edit', 42)],
                        ['id' => 43, 'title' => 'Kreyòl Kitchen Notes', 'words_added' => 1060, 'total_words' => 12400, 'target_words' => null, 'url' => route('books.edit', 43)],
                    ],
                ])],

            // Exports & publishing
            [ExportReadyMail::class, 'Exports & publishing', 'Export ready', 'Wired: Exporter::run() for PDF and EPUB',
                fn (array $s) => new ExportReadyMail($s['export'], $s['author'])],
            [ExportFailedMail::class, 'Exports & publishing', 'Export failed', 'Wired: Exporter::run(), GenerateExport::failed() and the stale-export sweep',
                fn (array $s) => new ExportFailedMail($s['failedExport'], $s['author'], 'An image in chapter 9 could not be read (cover-detail.heic).')],
            [SubmittedToStoresMail::class, 'Exports & publishing', 'Submitted to stores', 'Wired: PublishingController::submit (ready to upload)',
                fn (array $s) => new SubmittedToStoresMail($s['book'], $s['author'], ['amazon_kdp', 'apple_books', 'kobo', 'ingram_print'])],
            [BookLiveMail::class, 'Exports & publishing', 'Book live', 'Helper: Notify::bookLive — wire where a channel becomes live',
                fn (array $s) => new BookLiveMail($s['channel'], $s['author'], 'https://books.apple.com/book/id0000000000')],
            [BookUnpublishedMail::class, 'Exports & publishing', 'Book unpublished', 'Wired: PublishingController::unpublish',
                fn (array $s) => new BookUnpublishedMail($s['channel'], $s['author'], null, true)],

            // Billing
            [SubscriptionReceiptMail::class, 'Billing', 'Subscription started / receipt', 'Helper: Notify::subscriptionReceipt — wire on invoice.payment_succeeded',
                fn (array $s) => new SubscriptionReceiptMail($s['author'], 'Author', 1900, 'usd', Carbon::parse('2026-10-21'), Carbon::parse('2026-11-21'), 'https://invoice.stripe.com/i/acct_sample/test_sample', 'ECR-2026-0142', true, 'Visa ending 4242', 1000, Carbon::parse('2026-10-21'))],
            [PaymentFailedMail::class, 'Billing', 'Payment failed', 'Helper: Notify::paymentFailed — wire on invoice.payment_failed',
                fn (array $s) => new PaymentFailedMail($s['author'], 'Author', 1900, 'usd', Carbon::parse('2026-10-24'), Carbon::parse('2026-11-04'), null, 'Your card was declined.', 'Visa ending 4242')],
            [PlanChangedMail::class, 'Billing', 'Plan changed', 'Helper: Notify::planChanged — wire on customer.subscription.updated',
                fn (array $s) => new PlanChangedMail($s['author'], 'Author', 'Studio', now(), 7900, 'usd', 5000, true)],
            [SubscriptionCancelledMail::class, 'Billing', 'Subscription cancelled', 'Helper: Notify::subscriptionCancelled — wire on cancel / customer.subscription.updated',
                fn (array $s) => new SubscriptionCancelledMail($s['author'], 'Author', Carbon::parse('2026-11-21'))],
            [TrialEndingMail::class, 'Billing', 'Trial ending', 'Helper: Notify::trialEnding — wire on customer.subscription.trial_will_end',
                fn (array $s) => new TrialEndingMail($s['author'], 'Studio', now()->addDays(3), 7900, 'usd', false)],
            [CreditsLowMail::class, 'Billing', 'AI credits running low', 'Wired: CreditTransactionObserver (spend crossing 15%)',
                fn (array $s) => new CreditsLowMail($s['author'], 120, 1000, Carbon::parse('2026-11-01'))],

            // Commerce
            [OrderReceiptMail::class, 'Commerce', 'Order receipt (buyer)', 'Helper: Notify::orderReceipt — wire on checkout.session.completed',
                fn (array $s) => new OrderReceiptMail(
                    buyerEmail: 'reader@example.com',
                    buyerName: 'Jonas Pierre',
                    orderNumber: 'ECR-48213',
                    items: [
                        ['title' => $s['book']->title, 'format' => 'EPUB', 'amount_cents' => 1299, 'download_url' => url('/downloads/9f2c?signature=sample')],
                        ['title' => 'Kreyòl Kitchen Notes', 'format' => 'PDF', 'amount_cents' => 899, 'download_url' => url('/downloads/1a7e?signature=sample')],
                    ],
                    totalCents: 2198,
                    currency: 'usd',
                    placedAt: Carbon::parse('2026-10-21 14:03'),
                    licenseKey: 'SALT-7Q4M-2KX9',
                    linksExpireAt: Carbon::parse('2026-11-20'),
                    sellerName: 'Amélie Laurent',
                    paymentMethod: 'Visa ending 4242',
                )],
            [NewSaleMail::class, 'Commerce', 'New sale (author)', 'Helper: Notify::newSale — wire on checkout.session.completed',
                fn (array $s) => new NewSaleMail($s['author'], $s['book'], 'EPUB', 1299, 909, 'usd', Carbon::parse('2026-10-21 14:03'), null, 'Canada', 17)],
            [RefundProcessedMail::class, 'Commerce', 'Refund processed', 'Helper: Notify::refundProcessed — wire on charge.refunded',
                fn (array $s) => new RefundProcessedMail('reader@example.com', 'Jonas Pierre', 'ECR-48213', 1299, 'usd', Carbon::parse('2026-10-22'), [$s['book']->title], 'Visa ending 4242', null, 'Amélie Laurent')],
            [RoyaltyStatementMail::class, 'Commerce', 'Monthly royalty statement', 'Helper: Notify::royaltyStatement — wire in the statement job',
                fn (array $s) => new RoyaltyStatementMail($s['author'], Carbon::parse('2026-09-01'), [
                    ['title' => $s['book']->title, 'channel' => 'Écrivain store', 'units' => 31, 'royalty_cents' => 28179],
                    ['title' => $s['book']->title, 'channel' => 'Apple Books', 'units' => 12, 'royalty_cents' => 9072],
                    ['title' => 'Kreyòl Kitchen Notes', 'channel' => 'Écrivain store', 'units' => 9, 'royalty_cents' => 5661],
                ], 'usd', Carbon::parse('2026-10-31'), null, 36410)],
        ];

        $catalog = [];

        foreach ($entries as [$class, $group, $label, $trigger, $factory]) {
            $catalog[$class::slug()] = [
                'group' => $group,
                'label' => $label,
                'class' => $class,
                'trigger' => $trigger,
                'make' => fn (?string $bookTitle = null) => $factory(self::samples($bookTitle)),
            ];
        }

        return $catalog;
    }

    public static function make(string $slug, ?string $bookTitle = null): EcrivainMail
    {
        $entry = self::all()[$slug] ?? throw new \InvalidArgumentException("No email called [{$slug}].");

        return ($entry['make'])($bookTitle);
    }

    /**
     * In-memory sample models. Never saved.
     *
     * @return array<string, mixed>
     */
    public static function samples(?string $bookTitle = null): array
    {
        $author = (new User)->forceFill([
            'id' => 1,
            'name' => 'Amélie Laurent',
            'email' => 'amelie@example.com',
            'plan' => 'author',
            'daily_word_goal' => 750,
        ]);

        $reviewer = (new User)->forceFill([
            'id' => 2,
            'name' => 'Jonas Pierre',
            'email' => 'jonas@example.com',
            'plan' => 'free',
        ]);

        $book = (new Book)->forceFill([
            'id' => 42,
            'title' => $bookTitle ?? 'The Salt Road',
            'subtitle' => 'Three generations, one kitchen in Jacmel',
            'author_name' => 'Amélie Laurent',
            'word_count' => 48210,
            'target_words' => 80000,
            'list_price' => 12.99,
            'cover' => ['palette' => 0],
        ]);
        $book->setRelation('user', $author);

        $member = (new BookMember)->forceFill([
            'id' => 5,
            'email' => 'jonas@example.com',
            'role' => 'reviewer',
            'status' => 'invited',
            'invite_token' => 'Q2f8k1sN0aXb7LwPz4Rt9VcYe3HmJ6Ud',
            'invited_at' => Carbon::parse('2026-10-21 10:00'),
        ]);
        $member->setRelation('book', $book);
        $member->setRelation('inviter', $author);
        $member->setRelation('user', null);

        $accepted = (clone $member)->forceFill([
            'status' => 'active',
            'accepted_at' => Carbon::parse('2026-10-21 18:30'),
            'invite_token' => null,
        ]);
        $accepted->setRelation('user', $reviewer);

        $section = (new ManuscriptSection)->forceFill([
            'id' => 7,
            'title' => 'The Market at Croix-des-Bossales',
            'type' => 'chapter',
            'word_count' => 4120,
            'status' => 'review',
        ]);
        $section->setRelation('book', $book);

        $comment = (new Comment)->forceFill([
            'id' => 90,
            'user_id' => 1,
            'author_name' => 'Amélie Laurent',
            'body' => "@Jonas could you check the dates here? I think the market moved in 1749, not 1751.\nIf you have the Moreau de Saint-Méry citation to hand, even better.",
            'excerpt' => 'By the time the market moved inland in 1751, my great-grandmother had already',
            'status' => 'open',
        ]);
        $comment->setRelation('section', $section);

        $thread = (new Comment)->forceFill([
            'id' => 88,
            'user_id' => 2,
            'author_name' => 'Jonas Pierre',
            'body' => 'This paragraph reads beautifully, but the timeline in the second half confuses me.',
            'status' => 'open',
        ]);
        $thread->setRelation('section', $section);
        $thread->setRelation('replies', collect([1, 2]));

        $reply = (new Comment)->forceFill([
            'id' => 91,
            'parent_id' => 88,
            'user_id' => 1,
            'author_name' => 'Amélie Laurent',
            'body' => 'Fair point. I have moved the 1749 move to the start of the paragraph so it reads in order. Does that help?',
        ]);
        $reply->setRelation('section', $section);
        $reply->setRelation('parent', $thread);

        $review = (new EditorialReview)->forceFill([
            'id' => 300,
            'user_id' => 1,
            'agent' => EditorialAgent::Line,
            'status' => 'completed',
        ]);
        $review->setRelation('section', $section);
        $review->setRelation('findings', collect([
            ['critical', 'A sentence runs 94 words', 'The paragraph beginning “By the time the market moved inland” is one sentence. Readers lose the subject halfway through.'],
            ['warning', '“Suddenly” appears six times', 'Most uses can go without changing the meaning.'],
            ['warning', 'Tense shifts mid-scene', 'The market scene moves from past to present tense at “She walks to the stall”.'],
            ['suggestion', 'Repeated opening', 'Three paragraphs in a row begin with “The”.'],
            ['suggestion', 'Consider a stronger verb', '“Was going” could be “went”.'],
            ['suggestion', 'Dialogue tag', '“Exclaimed loudly” — the adverb repeats the verb.'],
        ])->map(fn ($f) => (new EditorialFinding)->forceFill(['severity' => $f[0], 'title' => $f[1], 'detail' => $f[2]])));

        $export = (new BookExport)->forceFill([
            'id' => 12,
            'format' => 'epub',
            'status' => 'ready',
            'byte_size' => 2_516_582,
            'word_count' => 48210,
            'created_at' => Carbon::parse('2026-10-21 16:02'),
            'updated_at' => Carbon::parse('2026-10-21 16:03'),
        ]);
        $export->setRelation('book', $book);

        $failed = (clone $export)->forceFill(['id' => 13, 'format' => 'pdf', 'status' => 'failed', 'byte_size' => null]);
        $failed->setRelation('book', $book);

        $channel = (new BookChannel)->forceFill([
            'id' => 3,
            'channel' => 'apple_books',
            'format' => 'ebook',
            'status' => 'live',
            'went_live_at' => Carbon::parse('2026-10-20'),
        ]);
        $channel->setRelation('book', $book);

        $ticket = (new SupportTicket)->forceFill(['subject' => 'My PDF export stops at chapter 12', 'status' => 'pending']);
        $ticket->setRelation('user', $author);
        $ticketReply = (new SupportMessage)->forceFill([
            'from_staff' => true,
            'body' => "Thanks for the details. The export stopped on a table that is wider than the page in chapter 12.\n\nNarrow its last column, or turn the table sideways in Design, and export again: it will run to the end.",
        ]);

        return [
            'ticket' => $ticket,
            'ticketReply' => $ticketReply,
            'author' => $author,
            'reviewer' => $reviewer,
            'book' => $book,
            'member' => $member,
            'acceptedMember' => $accepted,
            'section' => $section,
            'comment' => $comment,
            'reply' => $reply,
            'review' => $review,
            'export' => $export,
            'failedExport' => $failed,
            'channel' => $channel,
        ];
    }
}
