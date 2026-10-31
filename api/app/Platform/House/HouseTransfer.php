<?php

namespace App\Platform\House;

use App\Models\User;
use App\Platform\BookTransfer;
use Illuminate\Validation\ValidationException;

/**
 * Moving books from an author's account into a house publisher account
 * (ADR — house catalog).
 *
 * Ownership is books.user_id, so most of a book moves by itself: sections,
 * versions, comments, research, exports and covers all hang off the book id
 * (storage keys are per book, so no file moves). What carries the account
 * id of its own, and moves with the book here:
 *
 *  - the storefront product (products.user_id) — price, formats, licence,
 *    whether it is on sale, and the partner opt-in and rate;
 *  - the ISBNs assigned to the book (isbns.user_id): the numbers belong to
 *    the edition, so they follow it into the imprint's pool.
 *
 * The book's own columns travel unchanged: the ISBN plan, the rights and AI
 * declarations, the print settings, publication metadata and store
 * channels.
 *
 * What does NOT change, on purpose: who sold what. order_items.seller_id,
 * partner commissions and royalty statements say who owned the book when
 * it sold, and moving the book later does not rewrite that history.
 *
 * The previous owner becomes an `editor` member of the book unless asked not
 * to, so the person who wrote it can still open it. Everything happens in
 * one transaction with an audit row per book; a dry run is the same plan
 * with nothing written.
 *
 * The move itself is App\Platform\BookTransfer, which takes any account as
 * the target (ADR — moving books between any accounts); this is that, held
 * to a house account, with the plan's target under the key `house` as the
 * console and `house:transfer` have always read it.
 */
final class HouseTransfer
{
    public function __construct(private readonly BookTransfer $transfer) {}

    /**
     * What would happen to each of $bookIds. Nothing is written.
     *
     * @param  list<int>  $bookIds
     * @return array{house: array<string, mixed>, books: list<array<string, mixed>>, ok: bool}
     */
    public function plan(array $bookIds, User $house, bool $keepPrevious = true): array
    {
        $this->assertHouse($house);

        return $this->forHouse($this->transfer->plan($bookIds, $house, $keepPrevious), $house);
    }

    /**
     * Move the books. All or nothing: if any book cannot move, none does.
     *
     * $actor is null from the shell, where each audit row is the system's.
     *
     * @param  list<int>  $bookIds
     * @return array{house: array<string, mixed>, books: list<array<string, mixed>>, ok: bool}
     */
    public function apply(array $bookIds, User $house, string $reason, ?User $actor = null, bool $keepPrevious = true): array
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason: it is written to the audit log for every book.']);
        }

        $this->assertHouse($house);

        return $this->forHouse($this->transfer->apply($bookIds, $house, $reason, $actor, $keepPrevious), $house);
    }

    /** @return array{house: array<string, mixed>, books: list<array<string, mixed>>, ok: bool} */
    private function forHouse(array $plan, User $house): array
    {
        return [
            'house' => ['id' => $house->id, 'name' => $house->name, 'slug' => $house->house_slug],
            'books' => $plan['books'],
            'ok' => $plan['ok'],
        ];
    }

    private function assertHouse(User $house): void
    {
        if (! $house->isHouse()) {
            throw ValidationException::withMessages(['to' => 'Books can only be moved into a house publisher account.']);
        }
    }
}
