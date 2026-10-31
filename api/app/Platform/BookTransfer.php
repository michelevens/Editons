<?php

namespace App\Platform;

use App\Models\Book;
use App\Models\BookMember;
use App\Models\Isbn;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moving books from one account to any other (ADR — moving books between
 * any accounts).
 *
 * Ownership is books.user_id, so most of a book moves by itself: sections,
 * versions, comments, research, exports and covers all hang off the book id
 * (storage keys are per book, so no file moves). What carries the account
 * id of its own, and moves with the book here:
 *
 *  - the storefront product (products.user_id) — price, formats, licence,
 *    whether it is on sale, and the partner opt-in and rate;
 *  - the ISBNs assigned to the book (isbns.user_id): the numbers belong to
 *    the edition, so they follow it into the new owner's pool.
 *
 * The book's own columns travel unchanged: the ISBN plan, the rights and AI
 * declarations, the print settings, publication metadata and store
 * channels.
 *
 * What does NOT change, on purpose: who sold what. order_items.seller_id,
 * partner commissions and royalty statements say who owned the book when
 * it sold, and moving the book later does not rewrite that history.
 *
 * The target is any active account, a person's or a house publisher's
 * (HouseTransfer is this with the target held to a house). The previous
 * owner becomes an `editor` member of the book unless asked not to — by
 * default when they are a person; a house account is not a person, so it is
 * never kept. The new owner's own membership of the book, matched by user id
 * or by the address an invitation was sent to, is removed: the owner is not
 * a member (BookMember). Everything happens in one transaction with an
 * audit row per book; a dry run is the same plan with nothing written.
 */
final class BookTransfer
{
    /**
     * The account a shell or console reference names: an id, an email
     * address or a house slug. Null when there is none.
     */
    public function target(string $ref): ?User
    {
        $ref = trim($ref);

        if ($ref === '') {
            return null;
        }

        if (ctype_digit($ref)) {
            return User::find((int) $ref);
        }

        if (str_contains($ref, '@')) {
            return User::whereRaw('lower(email) = ?', [mb_strtolower($ref)])->first();
        }

        return User::where('account_kind', 'house')->where('house_slug', mb_strtolower($ref))->first();
    }

    /** Whether the previous owner stays as an editor when nobody says: yes for a person. */
    public static function keepsPreviousByDefault(?User $from): bool
    {
        return $from !== null && ! $from->isHouse();
    }

    /**
     * What would happen to each of $bookIds. Nothing is written.
     *
     * $keepPrevious null means each book's default (keepsPreviousByDefault).
     * $from, when given, is the account the books are being moved out of: a
     * book that is no longer there cannot move.
     *
     * @param  list<int>  $bookIds
     * @return array{to: array<string, mixed>, books: list<array<string, mixed>>, ok: bool, problems: list<string>}
     */
    public function plan(array $bookIds, User $to, ?bool $keepPrevious = null, ?User $from = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $bookIds)));
        $books = Book::withTrashed()->with('user:id,name,email,account_kind')->whereIn('id', $ids)->get()->keyBy('id');

        // Problems with the target stop every book.
        $accountProblems = [];

        if ($to->isSuspended()) {
            $accountProblems[] = 'The target account is suspended.';
        }

        if (DB::table('account_deletions')->where('user_id', $to->id)->where('status', 'scheduled')->exists()) {
            $accountProblems[] = 'The target account is scheduled for deletion.';
        }

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

            if ($from !== null && $book->user_id !== $from->id) {
                $problems[] = 'It is no longer in this account.';
            }

            if ($book->user_id === $to->id) {
                $problems[] = $to->isHouse() ? 'Already in this house account.' : 'Already in this account.';
            } elseif ($to->isHouse() && $book->user?->isHouse()) {
                // The house console moves authors' books in; one imprint to
                // another is not what it is for.
                $problems[] = 'It belongs to another house account.';
            }

            $isbns = Isbn::where('book_id', $book->id)->get(['id', 'isbn', 'user_id']);
            $clash = Isbn::where('user_id', $to->id)->whereIn('isbn', $isbns->pluck('isbn'))
                ->where(fn ($q) => $q->whereNull('book_id')->orWhere('book_id', '!=', $book->id))
                ->pluck('isbn');

            if ($clash->isNotEmpty()) {
                $problems[] = ($to->isHouse() ? 'The house account' : 'The target account').' already holds ISBN '.$clash->implode(', ').' for another book.';
            }

            $editions = Book::where('parent_book_id', $book->id)->whereNotIn('id', $ids)->where('user_id', '!=', $to->id)->pluck('id');

            if ($editions->isNotEmpty()) {
                $warnings[] = 'Its editions (#'.$editions->implode(', #').') are not in this transfer and stay with their owner.';
            }

            if ($book->parent_book_id && ! in_array($book->parent_book_id, $ids, true) && Book::whereKey($book->parent_book_id)->value('user_id') !== $to->id) {
                $warnings[] = "It is an edition of #{$book->parent_book_id}, which is not in this transfer.";
            }

            $restricted = DB::table('partner_sellers')->where('seller_id', $book->user_id)->count();

            if ($restricted > 0) {
                $warnings[] = "{$restricted} partner(s) invited by the previous owner may sell only that owner's books; they will not be able to sell this one.";
            }

            if ($this->targetMembership($book, $to)->exists()) {
                $warnings[] = 'The new owner is a member of this book now; that membership is removed, since the owner is not a member.';
            }

            $product = $book->product()->first();
            $person = $book->user && ! $book->user->isHouse();
            $keep = $person && ($keepPrevious ?? self::keepsPreviousByDefault($book->user));

            $rows[] = [
                'id' => $book->id,
                'title' => $book->title,
                'from' => $book->user ? ['id' => $book->user->id, 'name' => $book->user->name, 'house' => $book->user->isHouse()] : null,
                'ok' => $problems === [] && $accountProblems === [],
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
                'previous_owner' => $person ? ($keep ? 'becomes an editor of the book' : 'loses access') : null,
                'keep_previous' => $keep,
            ];
        }

        // A plan limit is the target's to manage; staff are told, not stopped.
        $limit = $to->planLimits()['books'] ?? null;
        $arriving = collect($rows)->filter(fn ($row) => $row['ok'])->count();

        if ($limit !== null && $arriving > 0 && $to->activeBookCount() + $arriving > $limit) {
            $accountWarnings = ["The target's plan allows {$limit} active book(s); it will hold ".($to->activeBookCount() + $arriving).'. Nothing is blocked, but they cannot create another until some are archived.'];
        }

        return [
            'to' => ['id' => $to->id, 'name' => $to->name, 'email' => $to->isHouse() ? null : $to->email, 'slug' => $to->house_slug, 'house' => $to->isHouse()],
            'books' => $rows,
            'problems' => $accountProblems,
            'warnings' => $accountWarnings ?? [],
            'ok' => $rows !== [] && $accountProblems === [] && collect($rows)->every(fn ($row) => $row['ok']),
        ];
    }

    /**
     * Move the books. All or nothing: if any book cannot move, none does.
     *
     * $actor is null from the shell, where each audit row is the system's.
     *
     * @param  list<int>  $bookIds
     * @return array{to: array<string, mixed>, books: list<array<string, mixed>>, ok: bool, problems: list<string>}
     */
    public function apply(array $bookIds, User $to, string $reason, ?User $actor = null, ?bool $keepPrevious = null, ?User $from = null): array
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason: it is written to the audit log for every book.']);
        }

        return AdminAudit::transaction(function () use ($bookIds, $to, $reason, $actor, $keepPrevious, $from) {
            // Locked, then planned: two transfers of the same books cannot
            // both pass the checks.
            Book::whereIn('id', $bookIds)->lockForUpdate()->get(['id']);

            $plan = $this->plan($bookIds, $to, $keepPrevious, $from);

            if (! $plan['ok']) {
                throw ValidationException::withMessages([
                    'books' => array_merge($plan['problems'], collect($plan['books'])->reject(fn ($row) => $row['ok'] || $row['problems'] === [])
                        ->map(fn ($row) => '#'.$row['id'].': '.implode(' ', $row['problems']))
                        ->values()->all()),
                ]);
            }

            foreach ($plan['books'] as $row) {
                $this->move(Book::findOrFail($row['id']), $to, $reason, $actor, $row['keep_previous']);
            }

            return $plan;
        });
    }

    /** The new owner's membership rows of $book: by user id, or by the address an invitation went to. */
    private function targetMembership(Book $book, User $to)
    {
        return BookMember::where('book_id', $book->id)->where(fn ($q) => $q
            ->where('user_id', $to->id)
            ->orWhereRaw('lower(email) = ?', [mb_strtolower((string) $to->email)]));
    }

    private function move(Book $book, User $to, string $reason, ?User $actor, bool $keepPrevious): void
    {
        $previous = $book->user_id;

        // Not fillable (ADR-0012), so forceFill: ownership never moves from
        // a request.
        $book->forceFill(['user_id' => $to->id])->save();

        $product = DB::table('products')->where('book_id', $book->id)->value('id');
        DB::table('products')->where('book_id', $book->id)->update(['user_id' => $to->id, 'updated_at' => now()]);
        $isbns = Isbn::where('book_id', $book->id)->pluck('isbn')->all();
        Isbn::where('book_id', $book->id)->update(['user_id' => $to->id]);

        // The new owner is the owner now; a membership row of its own would
        // be two records of one fact. An invitation sent to their address
        // and never accepted has no user id, so it is matched by address.
        $this->targetMembership($book, $to)->delete();

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

        // A house target keeps the house console's own event, so its history
        // reads as it always has.
        AdminAudit::record($to->isHouse() ? 'house.book_transferred' : 'book.transferred', $book, [
            'user_id' => $previous,
        ], array_filter([
            'user_id' => $to->id,
            'house' => $to->isHouse() ? $to->house_slug : null,
            'product_id' => $product,
            'isbns' => $isbns,
            'previous_owner_membership' => $member,
        ], fn ($v, $k) => $k !== 'house' || $v !== null, ARRAY_FILTER_USE_BOTH), $reason, $actor, system: $actor === null);
    }
}
