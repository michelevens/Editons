<?php

namespace App\Publishing;

use App\Models\Book;
use App\Models\BookChannel;

/**
 * What each ebook store will ask for, and whether the book has it.
 *
 * SubmissionCheck is the floor every store shares (a title, a description
 * of 100 characters, a category, a price). Stores add their own limits:
 * KDP's 4000-character description and seven keywords, Apple's 1400-pixel
 * cover, Google's 640. Each limit is the store's published figure
 * (config/publishing.php); a store that publishes none is not checked for
 * it.
 */
class StoreReadiness
{
    /** @return array<string, array<string, mixed>> channel => result */
    public function all(Book $book): array
    {
        $epub = $book->exports()->where('format', 'epub')->where('status', 'ready')->latest('id')->first();

        return collect(BookChannel::CHANNELS)
            ->filter(fn ($meta) => $meta[1] === 'ebook')
            ->mapWithKeys(fn ($meta, $channel) => [$channel => $this->for($book, $channel, $epub !== null)])
            ->all();
    }

    /** @return array{channel: string, label: string, status: string, items: list<array{key: string, status: string, message: string, fix: ?string}>} */
    public function for(Book $book, string $channel, bool $hasEpub): array
    {
        $limits = config("publishing.ebook.listing.{$channel}") ?? [];
        $meta = $book->meta ?? [];
        $label = BookChannel::CHANNELS[$channel][0];
        $items = [];
        $add = function (string $key, string $status, string $message, ?string $fix = null) use (&$items): void {
            $items[] = compact('key', 'status', 'message', 'fix');
        };

        $description = trim((string) ($meta['description'] ?? ''));
        $length = mb_strlen($description);

        if ($length === 0) {
            $add('description', 'fail', 'No description.', 'Write the description in Store listing.');
        } elseif (isset($limits['description_max']) && $length > $limits['description_max']) {
            $add('description', 'fail', "{$label} takes at most {$limits['description_max']} characters; this is {$length}.", 'Shorten the description.');
        } elseif (isset($limits['description_min']) && $length < $limits['description_min']) {
            $add('description', 'fail', "{$label} needs at least {$limits['description_min']} characters.", 'Lengthen the description.');
        } else {
            $add('description', 'pass', "Description, {$length} characters.");
        }

        $keywords = array_values(array_filter(array_map('trim', explode(',', (string) ($meta['keywords'] ?? '')))));

        if (isset($limits['keywords_max'])) {
            if ($keywords === []) {
                $add('keywords', 'warn', "No keywords: {$label} lets you add up to {$limits['keywords_max']}, which readers search by.", 'Add keywords in Store listing, separated by commas.');
            } elseif (count($keywords) > $limits['keywords_max']) {
                $add('keywords', 'warn', sprintf('%d keywords; %s takes %d.', count($keywords), $label, $limits['keywords_max']), 'Keep the strongest '.$limits['keywords_max'].'.');
            } else {
                $add('keywords', 'pass', count($keywords).' keyword'.(count($keywords) === 1 ? '' : 's').'.');
            }
        }

        trim((string) ($meta['category'] ?? '')) === ''
            ? $add('category', 'fail', 'No category.', 'Choose one in Store listing.')
            : $add('category', 'pass', 'Category: '.$meta['category'].'.');

        // The cover every store gets is drawn at a fixed size.
        [$short, $long] = [min(CoverRenderer::WIDTH, CoverRenderer::HEIGHT), max(CoverRenderer::WIDTH, CoverRenderer::HEIGHT)];
        $coverOk = $short >= ($limits['cover_min_short'] ?? 0) && $long >= ($limits['cover_min_long'] ?? 0);
        $add('cover', $coverOk ? 'pass' : 'fail', sprintf('Cover %d × %d pixels%s.', CoverRenderer::WIDTH, CoverRenderer::HEIGHT,
            isset($limits['cover_min_short']) ? ", {$label} asks for {$limits['cover_min_short']} on the short side" : (isset($limits['cover_min_long']) ? ", {$label} asks for {$limits['cover_min_long']} on the long side" : '')));

        $hasEpub
            ? $add('epub', 'pass', 'EPUB generated.')
            : $add('epub', 'warn', 'No EPUB yet: this is the file you upload.', 'Generate the EPUB under Files.');

        foreach (IsbnPlan::ebookIssues($book, $channel) as $issue) {
            $add('isbn', ...$issue);
        }

        if ($channel === 'amazon_kdp' && ($problem = Royalties::kindle(Royalties::prices($book)['ebook'], null, Royalties::prices($book)['print'])['problem'])) {
            $add('price', 'warn', $problem, 'Adjust the list price.');
        }

        $statuses = array_column($items, 'status');

        return [
            'channel' => $channel,
            'label' => $label,
            'status' => in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'pass'),
            'items' => $items,
        ];
    }
}
