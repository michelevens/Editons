<?php

namespace App\Mail\Writing;

use App\Editorial\Finding;
use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\Book;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;

/**
 * The Book Director's whole-book structural report is ready.
 *
 * Findings arrive as plain arrays (severity, title, detail) rather than
 * App\Editorial\Finding objects so the Mailable serialises cleanly onto the
 * queue; fromFindings() converts.
 */
class BookDirectorReportMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'writing';

    /**
     * @param  list<array{severity: string, title: string, detail: ?string}>  $findings
     */
    public function __construct(
        public Book $book,
        public User $recipient,
        public array $findings,
    ) {}

    /** @param  list<Finding>  $findings */
    public static function fromFindings(Book $book, User $recipient, array $findings): self
    {
        return new self($book, $recipient, array_map(fn (Finding $f) => [
            'severity' => $f->severity,
            'title' => $f->title,
            'detail' => $f->detail,
        ], $findings));
    }

    public function account(): ?User
    {
        return $this->recipient;
    }

    public function letter(): Letter
    {
        $findings = collect($this->findings)
            ->sortBy(fn ($f) => array_search($f['severity'], ['critical', 'warning', 'suggestion'], true))
            ->values();
        $counts = EditorialReviewCompleteMail::countBySeverity($findings);
        $total = $findings->count();

        $letter = Letter::make(__('Your Book Director report for “:title” is ready', ['title' => $this->book->title]))
            ->preheader($total === 0
                ? __('The structure holds up. Nothing to flag across the whole book.')
                : trans_choice('{1} :count note on the shape of the whole book.|[2,*] :count notes on the shape of the whole book.', $total, ['count' => $total]))
            ->subtitle(__('Book Director'))
            ->eyebrow(__('Whole-book report'))
            ->severity($counts['critical'] > 0 ? 'heads' : 'action')
            ->headline(__('How the whole book is holding together'))
            ->lede(self::greet($this->recipient->name, __('the Book Director has read “:title” from first chapter to last and looked at what no single-chapter review can: balance, pacing and promises kept.', [
                'title' => $this->book->title,
            ])))
            ->book($this->book)
            ->stats([
                ['label' => __('Words'), 'value' => number_format((int) $this->book->word_count)],
                ['label' => __('Notes'), 'value' => $total],
                ['label' => __('Critical'), 'value' => $counts['critical'], 'tone' => $counts['critical'] ? 'danger' : 'default'],
            ]);

        $letter->items(
            $findings->take(6)->map(fn ($f) => [
                'title' => $f['title'],
                'detail' => $f['detail'] ? Str::limit($f['detail'], 180) : null,
                'badge' => EditorialReviewCompleteMail::severityLabel($f['severity']),
                'tone' => EditorialReviewCompleteMail::severityTone($f['severity']),
            ])->all(),
            $total > 6 ? __('Highlights · :n more in the report', ['n' => $total - 6]) : __('The report'),
            empty: __('Nothing to flag. The chapters are in proportion and the book does what it sets out to do.'),
        );

        return $letter
            ->cta(__('Open the full report'), route('books.edit', $this->book))
            ->callout('info', __('The report describes; it does not change anything. Every decision about your book stays yours.'))
            ->reason(__('You received this because you asked the Book Director to review “:title”.', ['title' => $this->book->title]));
    }
}
