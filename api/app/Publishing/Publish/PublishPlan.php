<?php

namespace App\Publishing\Publish;

use App\Commerce\Print\LuluPrinting;
use App\Models\Book;
use App\Models\BookChannel;
use App\Models\User;
use App\Platform\Features;
use Illuminate\Validation\ValidationException;

/**
 * Step 2 of Publish: which channels a book goes to, and what each does.
 *
 * Five cards, each honest about what Écrivain can do there:
 *
 * - store: our storefront, automatic (Product + Commerce\Listing).
 * - lulu: print-on-demand for paperbacks sold in our store, automatic once
 *   Lulu's API keys are set (Commerce\Print), "Not connected" before.
 * - kdp_ebook / kdp_paperback: Amazon has no publishing API, so a guided
 *   page with every value ready to paste (KdpGuide); the author marks it
 *   submitted with the ASIN.
 * - google_play: no upload API either; a spreadsheet and files for Partner
 *   Center's bulk upload (GooglePlayBulk).
 * - apple_books: needs an Apple Books publisher account the platform does
 *   not have: coming soon, not selectable.
 *
 * A card backed by a store channel (KDP, Google) is the book's BookChannel
 * row, so the existing checks (SubmissionCheck, AiDisclosure, PrintCheck)
 * see the choice; the storefront and Lulu choices live in
 * books.publish_plan with the KDP values the author edited.
 */
class PublishPlan
{
    /** card => [label, store channel or null, format]. */
    public const CARDS = [
        'store' => ['Our store', null, 'ebook'],
        'lulu' => ['Lulu (print on demand for our store)', null, 'print'],
        'kdp_ebook' => ['Amazon KDP (ebook)', 'amazon_kdp', 'ebook'],
        'kdp_paperback' => ['Amazon KDP (paperback)', 'kdp_paperback', 'print'],
        'google_play' => ['Google Play Books', 'google_play', 'ebook'],
        'apple_books' => ['Apple Books', 'apple_books', 'ebook'],
    ];

    /** Print service whose rules and cover wrap each print card uses. */
    public const PRINT_SERVICE = ['kdp_paperback' => 'kdp', 'lulu' => 'lulu'];

    public function __construct(private readonly LuluPrinting $lulu) {}

    /**
     * The chosen cards. Before the author has chosen on this screen, what
     * the book already has: its store channels, and the storefront when it
     * has a product.
     *
     * @return list<string>
     */
    public static function chosen(Book $book): array
    {
        $plan = $book->publish_plan ?? [];

        if (isset($plan['channels']) && is_array($plan['channels'])) {
            return array_values(array_intersect(array_keys(self::CARDS), $plan['channels']));
        }

        $channels = $book->channels()->pluck('channel')->all();
        $chosen = [];

        foreach (self::CARDS as $card => [, $channel]) {
            if (($channel && in_array($channel, $channels, true)) || ($card === 'store' && $book->product()->exists())) {
                $chosen[] = $card;
            }
        }

        return array_values(array_diff($chosen, ['apple_books']));
    }

    /** Print services of the chosen print cards. @return list<string> */
    public static function services(Book $book): array
    {
        return array_values(array_map(fn ($card) => self::PRINT_SERVICE[$card], array_intersect(self::chosen($book), array_keys(self::PRINT_SERVICE))));
    }

    public static function wantsEbook(Book $book): bool
    {
        return array_intersect(self::chosen($book), ['store', 'kdp_ebook', 'google_play']) !== [];
    }

    /** Turn a card on or off. */
    public function choose(Book $book, string $card, bool $on): void
    {
        if (! isset(self::CARDS[$card])) {
            throw ValidationException::withMessages(['card' => 'Unknown channel.']);
        }

        if ($card === 'apple_books' && $on) {
            throw ValidationException::withMessages(['card' => 'Apple Books needs an Apple Books publisher account. Coming soon.']);
        }

        $chosen = self::chosen($book);
        $channel = self::CARDS[$card][1];

        if ($channel) {
            $row = $book->channels()->where('channel', $channel)->first();

            if ($on && ! $row) {
                $book->channels()->create(['channel' => $channel, 'format' => BookChannel::CHANNELS[$channel][1]]);
            }

            if (! $on && $row) {
                // A store that has the book is told to take it down; unticking
                // a card does not do that (PublishingController::unpublish).
                if (in_array($row->status, ['in_review', 'live'], true)) {
                    throw ValidationException::withMessages(['card' => $row->label().' has this book. Unpublish it there first, then mark it on Export & Publish.']);
                }

                $row->delete();
            }
        }

        $chosen = $on ? array_values(array_unique([...$chosen, $card])) : array_values(array_diff($chosen, [$card]));
        $this->save($book, ['channels' => $chosen]);
    }

    /**
     * Merge into books.publish_plan without touching updated_at: these are
     * choices about the listing, not changes to what the files contain.
     *
     * @param  array<string, mixed>  $changes
     */
    public function save(Book $book, array $changes): void
    {
        $plan = array_replace($book->publish_plan ?? [], $changes);

        if (! isset($plan['channels'])) {
            $plan['channels'] = self::chosen($book);
        }

        // toBase(): the Eloquent builder would stamp updated_at.
        Book::whereKey($book->id)->toBase()->update(['publish_plan' => json_encode($plan)]);
        $book->publish_plan = $plan;
        $book->syncOriginalAttribute('publish_plan');
    }

    /**
     * The cards, each with what will happen and where it stands.
     *
     * @return list<array<string, mixed>>
     */
    public function cards(Book $book, ?User $viewer = null): array
    {
        $chosen = self::chosen($book);
        $rows = $book->channels()->get()->keyBy('channel');
        $product = $book->product;
        $storefront = Features::enabled('storefront', $viewer);
        $lulu = $this->lulu->status($book);

        return collect(self::CARDS)->map(function (array $meta, string $card) use ($chosen, $rows, $product, $storefront, $lulu) {
            [$label, $channel, $format] = $meta;
            $row = $channel ? $rows->get($channel) : null;

            [$mode, $what] = match ($card) {
                'store' => $storefront
                    ? ['automatic', 'Automatic: one click puts the ebook on sale in your Écrivain store.']
                    : ['off', 'The storefront is switched off on this platform.'],
                'lulu' => $lulu['connected']
                    ? ['automatic', 'Automatic: Lulu checks the print files, then prints and ships each paperback sold in your store.']
                    : ['not_connected', 'Not connected: Lulu prints paperbacks for your store once the platform owner adds Lulu API keys.'],
                'kdp_ebook', 'kdp_paperback' => ['guided', 'Guided: you finish on Amazon in about 10 minutes, pasting each value from Écrivain.'],
                'google_play' => ['spreadsheet', 'Spreadsheet for Google\'s bulk upload, with the files named so Google matches them.'],
                'apple_books' => ['coming_soon', 'Connect your Apple Books account — coming soon.'],
            };

            return [
                'key' => $card,
                'label' => $label,
                'format' => $format,
                'channel' => $channel,
                'mode' => $mode,
                'what' => $what,
                'chosen' => in_array($card, $chosen, true),
                'selectable' => ! in_array($mode, ['coming_soon', 'off'], true),
                'status' => match (true) {
                    $card === 'store' => $product ? ($product->isTakenDown() ? 'taken_down' : ($product->isPublished() ? 'live' : 'draft')) : 'not_started',
                    $card === 'lulu' => $lulu['state'],
                    $row !== null => $row->status,
                    default => 'not_started',
                },
                'channel_id' => $row?->id,
                'external_id' => $row?->external_id,
                'submitted_at' => $row?->submitted_at?->toDateString(),
                'went_live_at' => $row?->went_live_at?->toDateString(),
            ];
        })->values()->all();
    }
}
