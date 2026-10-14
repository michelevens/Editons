<?php

namespace App\Publishing;

use App\Models\Book;
use App\Models\Isbn;

/**
 * What must be true before a book can go to a store.
 *
 * Stores reject submissions for missing metadata, and a rejection costs the
 * author days of waiting. Checking here turns a week-long round trip into an
 * immediate list, so this is deliberately stricter than the database: the
 * columns are nullable because a draft is allowed to be incomplete, and this
 * is the gate that says when it has stopped being a draft.
 */
class SubmissionCheck
{
    /**
     * Problems blocking submission, keyed by field.
     *
     * Keyed rather than a flat list so the Publishing screen can show each
     * message against the input it belongs to. An author told "description is
     * required" still has to find the description.
     *
     * @return array<string, string>
     */
    public function errors(Book $book): array
    {
        $errors = [];
        $meta = $book->meta ?? [];

        if (trim((string) $book->title) === '') {
            $errors['title'] = 'Stores list a book by its title.';
        }

        if (trim((string) $book->author_name) === '') {
            $errors['author_name'] = 'Add the author name as it appears on the cover.';
        }

        $description = trim((string) ($meta['description'] ?? ''));

        if ($description === '') {
            $errors['description'] = 'Stores show this to readers deciding whether to buy.';
        } elseif (mb_strlen($description) < 100) {
            // Not an arbitrary number: the major stores reject listings this
            // short, so accepting it here would just defer the rejection.
            $errors['description'] = 'Too short for most stores. Aim for 100 characters or more.';
        }

        if (trim((string) ($meta['category'] ?? '')) === '') {
            $errors['category'] = 'Pick the shelf your book belongs on.';
        }

        if ($book->list_price === null) {
            $errors['list_price'] = 'Set a list price.';
        } elseif ((float) $book->list_price <= 0) {
            $errors['list_price'] = 'A price of zero is not a free book — use a store promotion for that.';
        }

        // An ISBN is not required to submit (stores issue their own), but a
        // wrong one is worse than none: it points readers at another book.
        if ($book->isbn && ! Isbn::isValid($book->isbn)) {
            $errors['isbn'] = 'That is not a valid ISBN-13. Check the digits.';
        }

        if ($book->word_count < 500) {
            $errors['manuscript'] = 'The manuscript is too short to publish.';
        }

        if ($book->channels()->where('status', '!=', 'not_submitted')->doesntExist()
            && $book->channels()->doesntExist()) {
            $errors['channels'] = 'Choose at least one store.';
        }

        return $errors;
    }

    public function passes(Book $book): bool
    {
        return $this->errors($book) === [];
    }
}
