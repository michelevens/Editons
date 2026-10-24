<?php

namespace App\Publishing;

use App\Models\Book;
use App\Models\BookChannel;
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

        // Every store makes the author warrant the rights; the declaration
        // is where they say so, with the material they reuse.
        if (! RightsDeclaration::confirmed($book)) {
            $errors['rights_declaration'] = 'Confirm the rights declaration in ISBN & Rights.';
        }

        // KDP asks whether text, images or translations are AI-generated.
        if (AiDisclosure::required($book) && ! AiDisclosure::confirmed($book)) {
            $errors['ai_disclosure'] = 'Answer the AI-content questions in ISBN & Rights: KDP asks them.';
        }

        // A print store chosen and a print rule failed: the file would be
        // rejected (PrintCheck). Rules that rest on an estimated page count
        // only warn, so they are not here.
        foreach ($this->printResults($book) as $result) {
            foreach ($result['items'] as $item) {
                if ($item['status'] === 'fail') {
                    $errors["print_{$result['service']}_{$item['key']}"] = $result['label'].': '.$item['message'].($item['fix'] ? ' '.$item['fix'] : '');
                }
            }
        }

        return $errors;
    }

    /**
     * Things the author should know before submitting, which do not stop it.
     *
     * Pending suggestions are the one so far: the files print the book as if
     * each were rejected (Suggestions), so an author who meant to accept
     * them would upload a book without the changes they thought were in it.
     *
     * @return array<string, string>
     */
    public function warnings(Book $book): array
    {
        $warnings = [];

        if (($pending = $this->pendingSuggestions($book)) > 0) {
            $warnings['suggestions'] = $pending === 1
                ? '1 pending suggestion. Files leave it out until it is accepted; accept or reject it in the manuscript.'
                : "{$pending} pending suggestions. Files leave them out until they are accepted; accept or reject them in the manuscript.";
        }

        foreach ($this->printResults($book) as $result) {
            foreach ($result['items'] as $item) {
                if ($item['status'] === 'warn' && $item['key'] !== 'suggestions') {
                    $warnings["print_{$result['service']}_{$item['key']}"] = $result['label'].': '.$item['message'].($item['fix'] ? ' '.$item['fix'] : '');
                }
            }
        }

        if (($unsure = RightsDeclaration::unsure($book)) > 0) {
            $warnings['rights_unsure'] = $unsure === 1
                ? '1 item in your rights declaration has no permission yet.'
                : "{$unsure} items in your rights declaration have no permission yet.";
        }

        if (AiDisclosure::required($book) && AiDisclosure::confirmed($book)
            && ($outdated = AiDisclosure::for($book)['outdated']) !== []) {
            $warnings['ai_disclosure'] = "The book's records show more AI use (".implode(', ', $outdated).') than your AI-content answers. Review them in ISBN & Rights.';
        }

        if ($book->publication()['country'] && $book->channels()->where('format', 'print')->exists()) {
            $warnings['legal_deposit'] = LegalDeposit::for($book)['message'];
        }

        // Ebook stores' own limits beyond the shared floor above.
        $ebooks = $book->channels()->whereIn('status', ['not_submitted', 'ready_to_submit'])->pluck('channel')
            ->filter(fn ($channel) => (BookChannel::CHANNELS[$channel][1] ?? null) === 'ebook');
        $hasEpub = $book->exports()->where('format', 'epub')->where('status', 'ready')->exists();

        foreach ($ebooks as $channel) {
            foreach (app(StoreReadiness::class)->for($book, $channel, $hasEpub)['items'] as $item) {
                if ($item['status'] !== 'pass' && ! in_array($item['key'], ['description', 'category'], true)) {
                    $key = $item['key'] === 'epub' ? 'epub' : "store_{$channel}_{$item['key']}";
                    $warnings[$key] = ($item['key'] === 'epub' ? '' : BookChannel::CHANNELS[$channel][0].': ')
                        .$item['message'].($item['fix'] ? ' '.$item['fix'] : '');
                }
            }
        }

        return $warnings;
    }

    /**
     * Print checks for the print stores this book is going to.
     *
     * @return list<array<string, mixed>>
     */
    private function printResults(Book $book): array
    {
        $services = $book->channels()
            ->whereIn('channel', array_keys(BookChannel::PRINT_SERVICE))
            ->whereIn('status', ['not_submitted', 'ready_to_submit'])
            ->pluck('channel')
            ->map(fn ($channel) => BookChannel::PRINT_SERVICE[$channel])
            ->all();

        if ($services === []) {
            return [];
        }

        $check = app(PrintCheck::class);

        return array_map(fn ($service) => $check->for($book, $service), $services);
    }

    /** Suggested changes not yet accepted or rejected, across the book. */
    public function pendingSuggestions(Book $book): int
    {
        // A text search first, so a long book without a suggestion does not
        // send every chapter over the wire to be walked here. It can only
        // over-match (a chapter about insertions); the walk decides.
        return $book->sections()
            ->where(fn ($query) => $query
                ->whereRaw('content::text like ?', ['%"insertion"%'])
                ->orWhereRaw('content::text like ?', ['%"deletion"%']))
            ->get(['id', 'content'])
            ->sum(fn ($section) => Suggestions::count($section->content));
    }

    public function passes(Book $book): bool
    {
        return $this->errors($book) === [];
    }
}
