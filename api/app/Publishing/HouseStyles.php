<?php

namespace App\Publishing;

use App\Models\Book;
use App\Models\HouseStyle;
use App\Models\User;
use App\Platform\House\House;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * House styles: a book's design saved under a name and applied to other
 * books (ADR-0041 — Mirrored margins, trims, typography and house styles).
 *
 * A style is the whole of a book's `layout` — trim, margins, type, openers,
 * running heads, contents, boxes, paragraphs and headings — and nothing
 * else: applying one never touches the manuscript, the cover or the
 * publishing details. Existing exports go out of date, as after any design
 * change (PrintPages::fingerprint).
 *
 * Ownership. A style belongs to an account: the one that owns the books it
 * is made from and applied to (for a house book, the house account, run by
 * staff). It can only be applied to that account's books, so a style never
 * crosses into somebody else's catalogue, and only by someone who may both
 * edit the book and manage the account's styles.
 *
 * Following is explicit and safe. A book follows a style only when it was
 * applied with "follow"; then updating the style rewrites the design of
 * every book that follows it, and that book's own design edits are refused
 * until it is detached ("Customise this book"), which keeps the design as
 * it is and stops following. Applying without "follow" copies the design
 * once.
 */
class HouseStyles
{
    /** The layout keys a style carries: all of them. */
    public static function keys(): array
    {
        return array_keys(Book::DEFAULT_LAYOUT);
    }

    /** May $actor create, update, apply and delete this account's styles? */
    public static function canManage(User $actor, User $account): bool
    {
        return $actor->id === $account->id || House::canEditAccount($actor, $account);
    }

    /** The styles $actor can apply to this book: its owner's. */
    public function forBook(Book $book, User $actor)
    {
        $account = $book->user;

        if (! $account || ! self::canManage($actor, $account)) {
            return collect();
        }

        return HouseStyle::where('user_id', $account->id)->orderBy('name')->get();
    }

    /**
     * Save a book's current design as a new style of its owner's account.
     * With $follow the book then follows it.
     */
    public function save(Book $book, User $actor, string $name, bool $follow = true): HouseStyle
    {
        Gate::forUser($actor)->authorize('update', $book);
        $account = $book->user;
        $this->authorizeAccount($actor, $account);
        $name = trim($name);

        if (HouseStyle::where('user_id', $account->id)->where('name', $name)->exists()) {
            throw ValidationException::withMessages(['name' => "There is already a house style called “{$name}”."]);
        }

        return DB::transaction(function () use ($book, $actor, $account, $name, $follow) {
            $style = new HouseStyle(['name' => $name, 'design' => $this->design($book)]);
            $style->user_id = $account->id;
            $style->updated_by = $actor->id;
            $style->save();

            if ($follow) {
                $this->attach($book, $style);
            }

            return $style;
        });
    }

    /**
     * Replace a style's design with a book's, and rewrite every book that
     * follows it. The book it comes from then follows it too.
     *
     * @return int the books updated, the source included
     */
    public function update(HouseStyle $style, Book $from, User $actor): int
    {
        Gate::forUser($actor)->authorize('update', $from);
        $this->authorizeAccount($actor, $style->user);
        $this->sameAccount($style, $from);

        return DB::transaction(function () use ($style, $from, $actor) {
            $style->design = $this->design($from);
            $style->version = $style->version + 1;
            $style->updated_by = $actor->id;
            $style->save();

            $this->attach($from, $style);

            $count = 1;

            foreach (Book::where('house_style_id', $style->id)->whereKeyNot($from->id)->lockForUpdate()->get() as $book) {
                $this->write($book, $style, true);
                $count++;
            }

            return $count;
        });
    }

    public function rename(HouseStyle $style, User $actor, string $name): HouseStyle
    {
        $this->authorizeAccount($actor, $style->user);
        $name = trim($name);

        if (HouseStyle::where('user_id', $style->user_id)->where('name', $name)->whereKeyNot($style->id)->exists()) {
            throw ValidationException::withMessages(['name' => "There is already a house style called “{$name}”."]);
        }

        $style->update(['name' => $name]);

        return $style;
    }

    /** Delete a style; the books that followed it keep their design. */
    public function delete(HouseStyle $style, User $actor): void
    {
        $this->authorizeAccount($actor, $style->user);

        DB::transaction(function () use ($style) {
            Book::where('house_style_id', $style->id)->update(['house_style_id' => null, 'house_style_version' => null]);
            $style->delete();
        });
    }

    /**
     * Apply a style to one book: its design replaced by the style's, the
     * manuscript untouched. Throws when the actor may not, or the book is
     * another account's.
     *
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     */
    public function apply(HouseStyle $style, Book $book, User $actor, bool $follow = true): array
    {
        Gate::forUser($actor)->authorize('update', $book);
        $this->authorizeAccount($actor, $style->user);
        $this->sameAccount($style, $book);

        $before = ['layout' => $book->layout(), 'house_style_id' => $book->house_style_id];
        $this->write($book, $style, $follow);

        return ['before' => $before, 'after' => ['layout' => $book->layout(), 'house_style_id' => $book->house_style_id]];
    }

    /**
     * Apply a style to several books, each on its own: a book that cannot
     * take it is reported and the others go on.
     *
     * @param  list<int>  $bookIds
     * @return list<array{id: int, title: ?string, ok: bool, message: string}>
     */
    public function applyMany(HouseStyle $style, array $bookIds, User $actor, bool $follow = true, ?callable $each = null): array
    {
        $books = Book::whereIn('id', $bookIds)->get()->keyBy('id');
        $results = [];

        foreach (array_values(array_unique(array_map('intval', $bookIds))) as $id) {
            $book = $books[$id] ?? null;

            try {
                if (! $book) {
                    throw ValidationException::withMessages(['book' => 'No such book.']);
                }

                DB::transaction(function () use ($style, $book, $actor, $follow, $each) {
                    $change = $this->apply($style, $book, $actor, $follow);

                    if ($each) {
                        $each($book, $change);
                    }
                });

                $results[] = ['id' => $id, 'title' => $book->title, 'ok' => true,
                    'message' => $follow ? "Follows “{$style->name}”." : "Design copied from “{$style->name}”."];
            } catch (ValidationException $e) {
                $results[] = ['id' => $id, 'title' => $book?->title, 'ok' => false, 'message' => collect($e->errors())->flatten()->implode(' ')];
            } catch (AuthorizationException) {
                $results[] = ['id' => $id, 'title' => $book?->title, 'ok' => false, 'message' => 'You cannot change this book’s design.'];
            }
        }

        return $results;
    }

    /** Stop following: the book keeps its design and can be customised. */
    public function detach(Book $book, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $book);
        $book->forceFill(['house_style_id' => null, 'house_style_version' => null])->save();
    }

    /**
     * Refuse a design change to a book that follows a style: its design
     * is the style's until it is detached.
     *
     * @throws ValidationException
     */
    public static function refuseWhileFollowing(Book $book): void
    {
        if ($book->house_style_id !== null && ($style = $book->houseStyle)) {
            throw ValidationException::withMessages(['house_style' => "This book follows the house style “{$style->name}”. Choose “Customise this book” to change its design on its own."]);
        }
    }

    /**
     * What Design & Layout shows about house styles for this book.
     *
     * @return array<string, mixed>
     */
    public static function summary(Book $book, ?User $actor): array
    {
        $style = $book->house_style_id ? $book->houseStyle : null;
        $manage = $actor && $book->user && self::canManage($actor, $book->user);

        return [
            'following' => $style ? ['id' => $style->id, 'name' => $style->name, 'version' => $style->version,
                'current' => $book->house_style_version === $style->version] : null,
            'can_manage' => $manage,
            'styles' => $manage
                ? HouseStyle::where('user_id', $book->user_id)->withCount('books')->orderBy('name')->get()
                    ->map(fn (HouseStyle $s) => ['id' => $s->id, 'name' => $s->name, 'version' => $s->version, 'books' => $s->books_count,
                        'summary' => self::describe($s->design)])->all()
                : [],
        ];
    }

    /** A one-line description of a design: "6 x 9 in · Lora 11 pt · Standard margins". */
    public static function describe(array $design): string
    {
        $layout = array_merge(Book::DEFAULT_LAYOUT, $design);
        $margins = PageSetup::mirrored($layout) ? 'mirrored margins' : strtolower(PageSetup::PRESET_NAMES[$layout['margins']] ?? 'standard').' margins';

        return $layout['trim'].' · '.InteriorFonts::name($layout['font']).' '.rtrim(rtrim(number_format((float) $layout['size'], 1), '0'), '.').' pt · '.$margins;
    }

    /** A book's design, as a style stores it. */
    private function design(Book $book): array
    {
        return array_intersect_key($book->layout(), array_flip(self::keys()));
    }

    private function write(Book $book, HouseStyle $style, bool $follow): void
    {
        // Validated again on the way in: a style saved by an older version
        // resolves through Book::layout() like any stored layout.
        $layout = array_merge($book->layout(), array_intersect_key($style->design, array_flip(self::keys())));

        $book->forceFill([
            'layout' => $layout,
            'house_style_id' => $follow ? $style->id : null,
            'house_style_version' => $follow ? $style->version : null,
        ])->save();
    }

    private function attach(Book $book, HouseStyle $style): void
    {
        $book->forceFill(['house_style_id' => $style->id, 'house_style_version' => $style->version])->save();
    }

    private function authorizeAccount(User $actor, ?User $account): void
    {
        if (! $account || ! self::canManage($actor, $account)) {
            throw new AuthorizationException('You cannot manage this account’s house styles.');
        }
    }

    private function sameAccount(HouseStyle $style, Book $book): void
    {
        if ($style->user_id !== $book->user_id) {
            throw ValidationException::withMessages(['book' => 'A house style can only be applied to books of the account that owns it.']);
        }
    }
}
