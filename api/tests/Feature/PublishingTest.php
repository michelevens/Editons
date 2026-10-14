<?php

use App\Models\Book;
use App\Models\BookChannel;
use App\Models\Isbn;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\SubmissionCheck;
use Illuminate\Database\QueryException;

/**
 * Submitting to a store is the one irreversible-feeling action in the app: a
 * rejection costs days. These tests are mostly about what must stop it.
 */
function publishableBook(array $attributes = []): Book
{
    $book = Book::factory()->for(User::factory())->create(array_merge([
        'title' => 'A Practical Guide',
        'author_name' => 'A Writer',
        'list_price' => 14.99,
        'meta' => [
            'description' => str_repeat('A clear and useful description of the book. ', 4),
            'category' => 'Business & Economics / Small Business',
            'keywords' => 'practice, business',
        ],
    ], $attributes));

    ManuscriptSection::factory()->for($book)->create([
        'type' => 'chapter',
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Prose.']],
        ]]],
    ]);

    $book->forceFill(['word_count' => 20_000])->save();
    $book->channels()->create(['channel' => 'amazon_kdp', 'format' => 'ebook']);

    return $book->fresh();
}

it('lets a complete book through', function () {
    expect(app(SubmissionCheck::class)->errors(publishableBook()))->toBe([]);
});

it('blocks a book with no description', function () {
    $book = publishableBook(['meta' => ['category' => 'Fiction']]);

    expect(app(SubmissionCheck::class)->errors($book))->toHaveKey('description');
});

it('blocks a description too short for the stores', function () {
    // Not arbitrary: the major stores reject listings this short, so
    // accepting it here would only defer the rejection by a week.
    $book = publishableBook(['meta' => [
        'description' => 'Too short.',
        'category' => 'Fiction',
    ]]);

    expect(app(SubmissionCheck::class)->errors($book))->toHaveKey('description');
});

it('blocks a missing price, and a price of zero', function () {
    expect(app(SubmissionCheck::class)->errors(publishableBook(['list_price' => null])))
        ->toHaveKey('list_price');

    expect(app(SubmissionCheck::class)->errors(publishableBook(['list_price' => 0])))
        ->toHaveKey('list_price');
});

it('blocks an invalid ISBN but allows none at all', function () {
    // Stores issue their own if you have none. A wrong one is worse than
    // nothing: it points readers at a different book.
    expect(app(SubmissionCheck::class)->errors(publishableBook(['isbn' => '9780306406158'])))
        ->toHaveKey('isbn');

    expect(app(SubmissionCheck::class)->errors(publishableBook(['isbn' => null])))
        ->not->toHaveKey('isbn');
});

it('reports every problem at once, not the first', function () {
    // An author fixing one field at a time, with a week of waiting between
    // each, is the failure this avoids.
    $book = Book::factory()->for(User::factory())->create([
        'title' => '',
        'author_name' => null,
        'list_price' => null,
        'meta' => [],
    ]);

    $errors = app(SubmissionCheck::class)->errors($book);

    expect($errors)->toHaveKeys([
        'title', 'author_name', 'description', 'category', 'list_price', 'manuscript',
    ]);
});

it('keys problems to fields so each can be shown where it belongs', function () {
    // An author told "description is required" still has to find the
    // description. The screen needs the field name.
    $errors = app(SubmissionCheck::class)->errors(publishableBook(['meta' => []]));

    expect(array_keys($errors))->each->toBeString()
        ->and($errors['description'])->toBeString();
});

// --- Channels -----------------------------------------------------------

it('offers a book to a store only once', function () {
    // Without this, a double submit shows the same store twice on the screen.
    $book = publishableBook();

    expect(fn () => $book->channels()->create([
        'channel' => 'amazon_kdp',
        'format' => 'ebook',
    ]))->toThrow(QueryException::class);
});

it('refuses a channel row that contradicts itself', function () {
    // A live store with no submission date would be shown to an author as a
    // fact about the outside world that never happened.
    $book = publishableBook();

    expect(fn () => $book->channels()->create([
        'channel' => 'kobo',
        'format' => 'ebook',
        'status' => 'live',
    ]))->toThrow(QueryException::class);
});

it('rejects a status outside the known set', function () {
    $book = publishableBook();

    expect(fn () => $book->channels()->create([
        'channel' => 'kobo',
        'format' => 'ebook',
        'status' => 'probably_fine',
    ]))->toThrow(QueryException::class);
});

// --- ISBNs --------------------------------------------------------------

it('validates an ISBN-13 by its checksum, not its length', function () {
    // A mistyped digit passes a length check and is then printed on a cover.
    expect(Isbn::isValid('9780306406157'))->toBeTrue()
        ->and(Isbn::isValid('978-0-306-40615-7'))->toBeTrue()
        ->and(Isbn::isValid('9780306406158'))->toBeFalse()
        ->and(Isbn::isValid('123'))->toBeFalse()
        ->and(Isbn::isValid('not an isbn at all'))->toBeFalse();
});

it('will not assign one ISBN to two formats of a book', function () {
    // Each format needs its own number. Reusing one is a real publishing
    // error, not a UI slip, so the database refuses it.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $user->isbns()->create([
        'isbn' => '9780306406157',
        'book_id' => $book->id, 'edition_format' => 'ebook',
    ]);

    expect(fn () => $user->isbns()->create([
        'isbn' => '9781861972712',
        'book_id' => $book->id, 'edition_format' => 'ebook',
    ]))->toThrow(QueryException::class);
});

it('allows a different format of the same book', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $user->isbns()->create([
        'isbn' => '9780306406157',
        'book_id' => $book->id, 'edition_format' => 'ebook',
    ]);

    $user->isbns()->create([
        'isbn' => '9781861972712',
        'book_id' => $book->id, 'edition_format' => 'paperback',
    ]);

    expect($book->isbns()->count())->toBe(2);
});

it('keeps an unassigned ISBN from claiming a format', function () {
    // A number in the pool that says it covers the paperback is a number
    // nobody can account for.
    $user = User::factory()->create();

    expect(fn () => $user->isbns()->create([
        'isbn' => '9780306406157',
        'edition_format' => 'ebook',
    ]))->toThrow(QueryException::class);
});

it('allows many unassigned ISBNs, which is the pool', function () {
    $user = User::factory()->create();

    foreach (['9780306406157', '9781861972712', '9780140449136'] as $number) {
        $user->isbns()->create(['isbn' => $number]);
    }

    expect(Isbn::whereNull('book_id')->count())->toBe(3);
});

it('will not let the same number be entered twice in one account', function () {
    $user = User::factory()->create();

    $user->isbns()->create(['isbn' => '9780306406157']);

    expect(fn () => $user->isbns()->create(['isbn' => '9780306406157']))
        ->toThrow(QueryException::class);
});

// --- Editions -----------------------------------------------------------

it('will not let a book be its own parent', function () {
    $book = Book::factory()->for(User::factory())->create();

    expect(fn () => $book->forceFill(['parent_book_id' => $book->id])->save())
        ->toThrow(QueryException::class);
});

it('keeps an edition when its source book is deleted', function () {
    // Deleting the original should not destroy a translation somebody has
    // been working on for a month.
    $user = User::factory()->create();
    $original = Book::factory()->for($user)->create();

    $edition = Book::factory()->for($user)->create();
    $edition->forceFill([
        'parent_book_id' => $original->id,
        'edition_type' => 'translation',
    ])->save();

    $original->forceDelete();

    expect($edition->fresh())->not->toBeNull()
        ->and($edition->fresh()->parent_book_id)->toBeNull();
});

it('reports a channel label a person would recognise', function () {
    $channel = new BookChannel(['channel' => 'amazon_kdp', 'format' => 'ebook']);

    expect($channel->label())->toBe('Amazon Kindle');
});
