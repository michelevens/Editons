<?php

namespace App\Platform\House;

use App\Models\Book;
use App\Models\BookMember;
use App\Models\Isbn;
use App\Models\User;
use App\Platform\AdminAudit;
use Illuminate\Support\Facades\DB;
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
 */
final class HouseTransfer
{
    /**
     * What would happen to each of $bookIds. Nothing is written.
     *
     * @param  list<int>  $bookIds
     * @return array{house: array<string, mixed>, books: list<array<string, mixed>>, ok: bool}
     */
    public function plan(array $bookIds, User $house, bool $keepPrevious = true): array
    {
        $this->assertHouse($house);

        $ids = array_values(array_unique(array_map('intval', $bookIds)));
        $books = Book::withTrashed()->with('user:id,name,account_kind')->whereIn('id', $ids)->get()->keyBy('id');

        $rows = [];

        foreach ($ids as $id) {
            $book = $books[$id] ?? null;

            if (! $book) {
                $rows[] = ['id' => $id, 'title' => null, 'ok' => false, 'problems' => ['No book with this id.'], 'warnings' => []];

                continue;
            }

            $problems = [];
            $warnings = [];

            if ($book->trashed()) {
                $problems[] = 'The book is in the bin. Restore it first.';
            }

            if ($book->user_id === $house->id) {
                $problems[] = 'Already in this house account.';
            } elseif ($book->user?->isHouse()) {
                $problems[] = 'It belongs to another house account.';
            }

            $isbns = Isbn::where('book_id', $book->id)->get(['id', 'isbn', 'user_id']);
            $clash = Isbn::where('user_id', $house->id)->whereIn('isbn', $isbns->pluck('isbn'))
                ->where(fn ($q) => $q->whereNull('book_id')->orWhere('book_id', '!=', $book->id))
                ->pluck('isbn');

            if ($clash->isNotEmpty()) {
                $problems[] = 'The house account already holds ISBN '.$clash->implode(', ').' for another book.';
            }

            $editions = Book::where('parent_book_id', $book->id)->whereNotIn('id', $ids)->where('user_id', '!=', $house->id)->pluck('id');

            if ($editions->isNotEmpty()) {
                $warnings[] = 'Its editions (#'.$editions->implode(', #').') are not in this transfer and stay with their owner.';
            }

            if ($book->parent_book_id && ! in_array($book->parent_book_id, $ids, true) && Book::whereKey($book->parent_book_id)->value('user_id') !== $house->id) {
                $warnings[] = "It is an edition of #{$book->parent_book_id}, which is not in this transfer.";
            }

            $restricted = DB::table('partner_sellers')->where('seller_id', $book->user_id)->count();

            if ($restricted > 0) {
                $warnings[] = "{$restricted} partner(s) invited by the previous owner may sell only that owner's books; they will not be able to sell this one.";
            }

            $product = $book->product()->first();

            $rows[] = [
                'id' => $book->id,
                'title' => $book->title,
                'from' => $book->user ? ['id' => $book->user->id, 'name' => $book->user->name] : null,
                'ok' => $problems === [],
                'problems' => $problems,
                'warnings' => $warnings,
                'moves' => [
                    'sections' => $book->sections()->count(),
                    'versions' => (int) DB::table('manuscript_versions')
                        ->join('manuscript_sections', 'manuscript_sections.id', '=', 'manuscript_versions.manuscript_section_id')
                        ->where('manuscript_sections.book_id', $book->id)
                        ->count(),
                    'exports' => $book->exports()->count(),
                    'channels' => $book->channels()->count(),
                    'isbns' => $isbns->count(),
                    'product' => $product ? ['id' => $product->id, 'on_sale' => $product->isPublished(), 'partner_enabled' => (bool) $product->partner_enabled] : null,
                ],
                // History that stays where it is.
                'keeps' => [
                    'sold_items' => DB::table('order_items')->where('book_id', $book->id)->count(),
                ],
                'previous_owner' => $book->user && ! $book->user->isHouse()
                    ? ($keepPrevious ? 'becomes an editor of the book' : 'loses access')
                    : null,
            ];
        }

        return [
            'house' => ['id' => $house->id, 'name' => $house->name, 'slug' => $house->house_slug],
            'books' => $rows,
            'ok' => $rows !== [] && collect($rows)->every(fn ($row) => $row['ok']),
        ];
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

        return AdminAudit::transaction(function () use ($bookIds, $house, $reason, $actor, $keepPrevious) {
            // Locked, then planned: two transfers of the same books cannot
            // both pass the checks.
            Book::whereIn('id', $bookIds)->lockForUpdate()->get(['id']);

            $plan = $this->plan($bookIds, $house, $keepPrevious);

            if (! $plan['ok']) {
                throw ValidationException::withMessages([
                    'books' => collect($plan['books'])->reject(fn ($row) => $row['ok'])
                        ->map(fn ($row) => '#'.$row['id'].': '.implode(' ', $row['problems']))
                        ->values()->all(),
                ]);
            }

            foreach ($plan['books'] as $row) {
                $this->move(Book::findOrFail($row['id']), $house, $reason, $actor, $keepPrevious);
            }

            return $plan;
        });
    }

    private function move(Book $book, User $house, string $reason, ?User $actor, bool $keepPrevious): void
    {
        $previous = $book->user_id;

        // Not fillable (ADR-0012), so forceFill: ownership never moves from
        // a request.
        $book->forceFill(['user_id' => $house->id])->save();

        $product = DB::table('products')->where('book_id', $book->id)->value('id');
        DB::table('products')->where('book_id', $book->id)->update(['user_id' => $house->id, 'updated_at' => now()]);
        $isbns = Isbn::where('book_id', $book->id)->pluck('isbn')->all();
        Isbn::where('book_id', $book->id)->update(['user_id' => $house->id]);

        // The house account is the owner now; a membership row of its own
        // would be two records of one fact.
        BookMember::where('book_id', $book->id)->where('user_id', $house->id)->delete();

        $member = null;

        if ($keepPrevious) {
            $row = BookMember::firstOrNew(['book_id' => $book->id, 'user_id' => $previous]);
            $row->forceFill([
                'email' => $row->email ?? User::whereKey($previous)->value('email'),
                'role' => 'editor',
                'status' => 'active',
                'accepted_at' => $row->accepted_at ?? now(),
                'invite_token' => null,
            ]);
            $row->book_id = $book->id;
            $row->save();
            $member = 'editor';
        } else {
            BookMember::where('book_id', $book->id)->where('user_id', $previous)->delete();
        }

        AdminAudit::record('house.book_transferred', $book, [
            'user_id' => $previous,
        ], [
            'user_id' => $house->id,
            'house' => $house->house_slug,
            'product_id' => $product,
            'isbns' => $isbns,
            'previous_owner_membership' => $member,
        ], $reason, $actor, system: $actor === null);
    }

    private function assertHouse(User $house): void
    {
        if (! $house->isHouse()) {
            throw ValidationException::withMessages(['to' => 'Books can only be moved into a house publisher account.']);
        }
    }
}
