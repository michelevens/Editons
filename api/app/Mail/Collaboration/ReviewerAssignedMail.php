<?php

namespace App\Mail\Collaboration;

use App\Mail\EcrivainMail;
use App\Mail\Support\Letter;
use App\Models\ManuscriptSection;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;

/** Somebody was made the reviewer of a chapter on the workflow board. */
class ReviewerAssignedMail extends EcrivainMail implements ShouldQueue
{
    public const CATEGORY = 'collaboration';

    public function __construct(
        public ManuscriptSection $section,
        public User $reviewer,
        public ?User $assignedBy = null,
    ) {}

    public function account(): ?User
    {
        return $this->reviewer;
    }

    public function letter(): Letter
    {
        $book = $this->section->book;
        $by = $this->assignedBy?->name ?? $book->user?->name ?? __('The author');

        return Letter::make(__(':by asked you to review “:chapter”', ['by' => $by, 'chapter' => $this->section->title]))
            ->preheader(__('“:chapter” from “:title” is waiting for your review.', ['chapter' => $this->section->title, 'title' => $book->title]))
            ->subtitle(__('Review requested'))
            ->eyebrow(__('Your turn'))
            ->severity('action')
            ->headline(__('A chapter is waiting for your review'))
            ->lede(self::greet($this->reviewer->name, __(':by has assigned “:chapter” to you on the review board. Read it, leave comments where something needs work, and move it on when you are done.', [
                'by' => $by,
                'chapter' => $this->section->title,
            ])))
            ->book($book)
            ->facts([
                __('Chapter') => $this->section->title,
                __('Length') => __(':n words', ['n' => number_format((int) $this->section->word_count)]),
                __('Status') => self::status($this->section->status),
                __('Assigned by') => $by,
            ])
            ->cta(__('Open the chapter'), route('books.edit', ['book' => $book, 'section' => $this->section->id]))
            ->reason(__('You received this because you were assigned as a reviewer on “:title”.', ['title' => $book->title]));
    }

    private static function status(?string $status): string
    {
        return match ($status) {
            'draft' => __('Draft'),
            'review' => __('In review'),
            'revisions' => __('Needs revisions'),
            'approved' => __('Approved'),
            default => (string) $status,
        };
    }
}
