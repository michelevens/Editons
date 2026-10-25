<?php

namespace App\Publishing\Publish;

use App\Mail\Notify;
use App\Models\Book;
use App\Models\BookChannel;
use App\Models\User;
use App\Platform\AdminAudit;
use App\Platform\House\House;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * "I've submitted it": the author says a store has the book.
 *
 * Écrivain cannot see into KDP or Partner Center, so the author's word is
 * the record, with what makes it checkable: the store's own id (an ASIN at
 * Amazon, in its documented shape) and the date. The channel becomes
 * `in_review` (submitted, the store is reviewing) or `live`, which every
 * screen that lists channels shows (Overview, Sales & Distribution, the
 * House catalog). A house book's submission is also written to the admin
 * audit log, as every other change staff make to it is.
 */
class ChannelSubmission
{
    /** Cards that are submitted by hand, and the store channel each is. */
    public const CARDS = [
        'kdp_ebook' => 'amazon_kdp',
        'kdp_paperback' => 'kdp_paperback',
        'google_play' => 'google_play',
    ];

    /** @throws ValidationException */
    public function record(Book $book, string $card, ?string $externalId, string $date, string $state, User $actor): BookChannel
    {
        $channel = self::CARDS[$card] ?? throw ValidationException::withMessages(['card' => 'That channel is not submitted by hand.']);
        $externalId = $externalId !== null ? strtoupper(trim($externalId)) : null;

        if (str_starts_with($card, 'kdp_')) {
            if ($externalId === null || ! preg_match(config('publishing.publish.kdp.asin'), $externalId)) {
                throw ValidationException::withMessages(['external_id' => 'An ASIN is 10 letters and digits: B0… for a Kindle eBook, or the ISBN-10 for a paperback. KDP shows it on your Bookshelf.']);
            }
        } elseif ($externalId !== null && $externalId !== '' && ! preg_match('/^(GGKEY:)?[A-Z0-9_-]{4,40}$/', $externalId)) {
            throw ValidationException::withMessages(['external_id' => 'That does not look like a Google Play identifier (an ISBN or GGKEY).']);
        }

        if (! in_array($state, ['in_review', 'live'], true)) {
            throw ValidationException::withMessages(['state' => 'Choose submitted or live.']);
        }

        $on = Carbon::parse($date)->startOfDay();

        if ($on->isFuture() || $on->lt(Carbon::parse('2000-01-01'))) {
            throw ValidationException::withMessages(['date' => 'Give the date you submitted it, today or earlier.']);
        }

        $write = function () use ($book, $channel, $card, $externalId, $on, $state, $actor) {
            $row = $book->channels()->firstOrCreate(['channel' => $channel], ['format' => BookChannel::CHANNELS[$channel][1]]);
            $before = ['status' => $row->status, 'external_id' => $row->external_id];
            $wasLive = $row->status === 'live';

            // The dates are facts the author states here, not fields a
            // client can set (BookChannel's fillable leaves them out).
            $row->forceFill([
                'status' => $state,
                'external_id' => $externalId ?: null,
                'submitted_at' => $row->submitted_at && $row->status !== 'unpublished' ? $row->submitted_at : $on,
                'went_live_at' => $state === 'live' ? ($row->went_live_at ?? $on) : null,
                'submitted_by' => $actor->id,
            ])->save();

            if (! in_array($card, PublishPlan::chosen($book), true)) {
                app(PublishPlan::class)->save($book, ['channels' => [...PublishPlan::chosen($book), $card]]);
            }

            if ($book->status === 'draft' || ($state === 'live' && $book->status === 'in_review')) {
                $book->forceFill(['status' => $state === 'live' ? 'published' : 'in_review'])->save();
            }

            if (House::isHouseBook($book)) {
                AdminAudit::record('house.channel_submitted', $book, $before, [
                    'channel' => $channel, 'status' => $state, 'external_id' => $externalId, 'date' => $on->toDateString(),
                ], 'Publish: marked submitted', $actor);
            }

            return [$row, $wasLive];
        };

        [$row, $wasLive] = House::isHouseBook($book) ? AdminAudit::transaction($write) : $write();

        if ($state === 'live' && ! $wasLive) {
            Notify::bookLive($row->fresh('book'));
        }

        return $row;
    }
}
