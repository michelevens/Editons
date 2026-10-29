<?php

use App\Models\Book;
use App\Models\BookChannel;
use App\Models\HouseStyle;
use App\Models\ShelfView;
use App\Models\User;
use App\Platform\House\HouseAccounts;
use App\Publishing\PrintPages;
use App\Publishing\Publish\Freshness;
use App\Shelf\Shelf;
use Illuminate\Support\Facades\DB;

/*
 * The shelf (ADR-0045 — Shelf views and bulk actions): search, sort, series
 * groups, pages and table columns on the server; saved views per person;
 * never another account's books.
 */

function shelfBook(User $owner, array $attributes = []): Book
{
    return Book::factory()->for($owner)->create(array_merge(['subtitle' => null, 'author_name' => 'Anonyme', 'series_name' => null], $attributes));
}

/** The shelf's props for a request. */
function shelfProps($test, User $user, array $query = []): array
{
    return $test->actingAs($user)->get('/books'.($query ? '?'.http_build_query($query) : ''))->assertOk()->viewData('page')['props'];
}

/** A valid ISBN-13 (the checksum holds) for a number. */
function shelfIsbn(int $n): string
{
    $digits = '978000'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    $sum = 0;

    foreach (str_split($digits) as $i => $d) {
        $sum += (int) $d * ($i % 2 === 0 ? 1 : 3);
    }

    return $digits.((10 - $sum % 10) % 10);
}

/** A store channel at a status, with the dates the table's check asks for. */
function shelfChannel(Book $book, string $channel, string $status): BookChannel
{
    $row = new BookChannel(['channel' => $channel, 'status' => $status, 'format' => 'ebook']);
    $row->book_id = $book->id;
    $row->forceFill(['submitted_at' => now(), 'went_live_at' => $status === 'live' ? now() : null])->save();

    return $row;
}

function shelfTitles(array $props): array
{
    return array_column($props['books'], 'title');
}

beforeEach(function () {
    $this->author = User::factory()->create();
});

it('opens on today\'s shelf when nothing was saved: every book, last updated first, as cards', function () {
    $old = shelfBook($this->author, ['title' => 'Old']);
    $new = shelfBook($this->author, ['title' => 'New']);
    Book::whereKey($old->id)->update(['updated_at' => now()->subDay()]);

    $props = shelfProps($this, $this->author);

    expect($props['shelf']['state'])->toBe(Shelf::DEFAULT)
        ->and(shelfTitles($props))->toBe(['New', 'Old'])
        ->and($props['filter'])->toBe('all')
        ->and($props['counts'])->toMatchArray(['all' => 2, 'active' => 2, 'published' => 0, 'archived' => 0]);
});

it('searches title, subtitle, series and author with accents ignored', function () {
    shelfBook($this->author, ['title' => 'Réussir l’écrit', 'series_name' => 'Écrivain Prépa']);
    shelfBook($this->author, ['title' => 'Lakou', 'subtitle' => 'Istwa lakou a', 'author_name' => 'Jérôme Cléry']);
    shelfBook($this->author, ['title' => 'Other']);

    expect(shelfTitles(shelfProps($this, $this->author, ['q' => 'Reussir'])))->toBe(['Réussir l’écrit'])
        ->and(shelfTitles(shelfProps($this, $this->author, ['q' => 'ecrivain prepa'])))->toBe(['Réussir l’écrit'])
        ->and(shelfTitles(shelfProps($this, $this->author, ['q' => 'RÉUSSIR'])))->toBe(['Réussir l’écrit'])
        ->and(shelfTitles(shelfProps($this, $this->author, ['q' => 'istwa'])))->toBe(['Lakou'])
        ->and(shelfTitles(shelfProps($this, $this->author, ['q' => 'jerome clery'])))->toBe(['Lakou'])
        // LIKE's wildcards are searched for, not obeyed.
        ->and(shelfTitles(shelfProps($this, $this->author, ['q' => '%'])))->toBe([])
        ->and(shelfTitles(shelfProps($this, $this->author, ['q' => '_'])))->toBe([]);
});

it('keeps the search text when a title or series changes', function () {
    $book = shelfBook($this->author, ['title' => 'Draft']);
    $book->update(['title' => 'Kréyòl pou tout moun']);

    expect($book->fresh()->search_text)->toContain('kreyol pou tout moun')
        ->and(shelfTitles(shelfProps($this, $this->author, ['q' => 'kreyol'])))->toBe(['Kréyòl pou tout moun']);
});

it('sorts by title, series and number, words, status and last update', function () {
    $b = shelfBook($this->author, ['title' => 'banane', 'series_name' => 'Zeta', 'series_number' => 1, 'status' => 'published']);
    $a = shelfBook($this->author, ['title' => 'Avocat', 'series_name' => 'Alpha', 'series_number' => 2, 'status' => 'archived']);
    $c = shelfBook($this->author, ['title' => 'Citron', 'series_name' => 'Alpha', 'series_number' => 1, 'status' => 'draft']);
    $d = shelfBook($this->author, ['title' => 'Datte', 'status' => 'in_review']);
    Book::whereKey($a->id)->update(['word_count' => 300, 'updated_at' => now()->subDays(3)]);
    Book::whereKey($b->id)->update(['word_count' => 100, 'updated_at' => now()->subDays(1)]);
    Book::whereKey($c->id)->update(['word_count' => 400, 'updated_at' => now()->subDays(2)]);
    Book::whereKey($d->id)->update(['word_count' => 200, 'updated_at' => now()]);

    expect(shelfTitles(shelfProps($this, $this->author, ['sort' => 'title'])))->toBe(['Avocat', 'banane', 'Citron', 'Datte'])
        ->and(shelfTitles(shelfProps($this, $this->author, ['sort' => 'title', 'dir' => 'desc'])))->toBe(['Datte', 'Citron', 'banane', 'Avocat'])
        ->and(shelfTitles(shelfProps($this, $this->author, ['sort' => 'series'])))->toBe(['Citron', 'Avocat', 'banane', 'Datte'])
        ->and(shelfTitles(shelfProps($this, $this->author, ['sort' => 'words'])))->toBe(['Citron', 'Avocat', 'Datte', 'banane'])
        ->and(shelfTitles(shelfProps($this, $this->author, ['sort' => 'status'])))->toBe(['Citron', 'Datte', 'banane', 'Avocat'])
        ->and(shelfTitles(shelfProps($this, $this->author, ['sort' => 'updated'])))->toBe(['Datte', 'banane', 'Citron', 'Avocat'])
        // Nonsense falls back to the default.
        ->and(shelfProps($this, $this->author, ['sort' => 'drop table', 'dir' => 'sideways'])['shelf']['state']['sort'])->toBe('updated');
});

it('groups by series: series in name order, books by number, no series last', function () {
    shelfBook($this->author, ['title' => 'Prépa 2', 'series_name' => 'Écrivain Prépa', 'series_number' => 2]);
    shelfBook($this->author, ['title' => 'Loose']);
    shelfBook($this->author, ['title' => 'Prépa 1', 'series_name' => 'Écrivain Prépa', 'series_number' => 1]);
    shelfBook($this->author, ['title' => 'Anthology 1', 'series_name' => 'Anthology', 'series_number' => 1]);

    $props = shelfProps($this, $this->author, ['group' => 'series', 'sort' => 'title']);

    expect(shelfTitles($props))->toBe(['Anthology 1', 'Prépa 1', 'Prépa 2', 'Loose'])
        ->and($props['shelf']['groups'])->toBe([
            ['name' => 'Anthology', 'count' => 1],
            ['name' => 'Écrivain Prépa', 'count' => 2],
            ['name' => null, 'count' => 1],
        ])
        ->and($props['books'][1]['series'])->toBe(['name' => 'Écrivain Prépa', 'number' => 1]);
});

it('pages on the server, with the whole state in every page link', function () {
    foreach (range(1, 30) as $n) {
        shelfBook($this->author, ['title' => sprintf('Book %02d', $n)]);
    }

    $first = shelfProps($this, $this->author, ['per_page' => 25, 'sort' => 'title']);
    $second = shelfProps($this, $this->author, ['per_page' => 25, 'sort' => 'title', 'page' => 2]);

    expect($first['books'])->toHaveCount(25)
        ->and($first['shelf']['page'])->toMatchArray(['current' => 1, 'last' => 2, 'total' => 30, 'from' => 1, 'to' => 25])
        ->and($second['books'])->toHaveCount(5)
        ->and($second['books'][0]['title'])->toBe('Book 26')
        // "Select all in this view" is every book, not this page's.
        ->and($first['shelf']['ids'])->toHaveCount(30)
        ->and(collect($first['shelf']['page']['links'])->pluck('url')->filter()->every(fn ($u) => str_contains($u, 'sort=title') && str_contains($u, 'per_page=25')))->toBeTrue();
});

it('fills the table\'s columns: ISBNs per format, live channels, last export with a stale flag, house style', function () {
    $book = pfBook($this->author);
    pfIsbn($book, '9780306406157', 'ebook');
    pfIsbn($book, '9781861972712', 'paperback');
    shelfChannel($book, 'google_play', 'live');
    shelfChannel($book, 'kobo', 'in_review');
    pfFile($book, 'pdf_interior');
    pfFile($book, 'epub');
    $style = new HouseStyle(['name' => 'Prépa', 'design' => []]);
    $style->forceFill(['user_id' => $this->author->id, 'version' => 1])->save();
    $book->forceFill(['house_style_id' => $style->id])->save();

    $state = ['view' => 'table', 'columns' => 'isbns,channels,exports,house_style'];
    $row = (array) shelfProps($this, $this->author, $state)['books'][0]['columns'];

    expect($row['isbns'])->toBe(['ebook' => '9780306406157', 'paperback' => '9781861972712'])
        ->and($row['channels'])->toBe(['Google Play Books'])
        ->and($row['house_style'])->toBe('Prépa')
        ->and($row['exports']['pdf_interior']['fresh'])->toBeTrue()
        ->and($row['exports']['epub']['fresh'])->toBeTrue()
        ->and($row['exports']['pdf']['at'])->toBeNull();

    // A margin changed after the interior was typeset: stale, as Publish says.
    $book->update(['layout' => array_merge($book->layout(), ['margins' => 'Wide'])]);
    $row = (array) shelfProps($this, $this->author, $state)['books'][0]['columns'];

    expect($row['exports']['pdf_interior']['fresh'])->toBeFalse()
        ->and($row['exports']['pdf_interior']['reason'])->toBe(Freshness::file($book->fresh(), 'pdf_interior')['reason'])
        ->and($row['exports']['epub']['fresh'])->toBeTrue();

    // The card view does not pay for columns.
    expect((array) shelfProps($this, $this->author, ['view' => 'cards'])['books'][0]['columns'])->toBe([]);
});

it('agrees with Freshness::file and PrintPages::fingerprint book by book', function () {
    $book = pfBook($this->author);
    $empty = shelfBook($this->author);
    pfFile($book, 'pdf_interior');
    pfFile($book, 'cover_wrap', ['service' => 'kdp']);

    $aggregate = PrintPages::sectionsAggregate([$book->id, $empty->id]);
    $many = Freshness::many(collect([$book->fresh(), $empty->fresh()]), ['pdf_interior', 'cover_wrap', 'epub']);

    expect(PrintPages::fingerprint($book->fresh(), $aggregate[$book->id]))->toBe(PrintPages::fingerprint($book->fresh()))
        ->and(PrintPages::fingerprint($empty->fresh(), $aggregate[$empty->id]))->toBe(PrintPages::fingerprint($empty->fresh()));

    foreach (['pdf_interior', 'cover_wrap', 'epub'] as $format) {
        expect($many[$book->id][$format]['fresh'])->toBe(Freshness::file($book->fresh(), $format)['fresh']);
    }
});

it('loads a page of 60 books with every column in the same number of queries as 10', function () {
    $count = function (int $books) {
        $user = User::factory()->create();

        foreach (range(1, $books) as $n) {
            $book = shelfBook($user, ['title' => "B{$n}", 'series_name' => $n % 2 ? 'S' : null, 'series_number' => $n]);
            shelfChannel($book, 'google_play', 'live');
            pfIsbn($book, shelfIsbn($n), 'ebook');
        }

        $this->actingAs($user)->get('/books?per_page=100')->assertOk(); // warm up
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get('/books?per_page=100&view=table&group=series&columns='.implode(',', Shelf::COLUMNS))->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $ten = $count(10);
    $sixty = $count(60);

    expect($sixty)->toBe($ten)->and($sixty)->toBeLessThan(40);
});

it('remembers the current view per person and restores it on return', function () {
    shelfBook($this->author, ['title' => 'Mine']);
    $state = ['q' => 'mine', 'sort' => 'title', 'dir' => 'asc', 'group' => 'series', 'view' => 'table', 'columns' => ['isbns', 'words', 'bogus'], 'filter' => 'active', 'per_page' => 25];

    $this->actingAs($this->author)->putJson('/shelf/books/current', ['state' => $state])->assertOk();

    $restored = shelfProps($this, $this->author)['shelf']['state'];
    expect($restored)->toMatchArray(['q' => 'mine', 'sort' => 'title', 'group' => 'series', 'view' => 'table', 'filter' => 'active', 'per_page' => 25])
        // Known columns only, in the table's order.
        ->and($restored['columns'])->toBe(['words', 'isbns']);

    // Somebody else's shelf is untouched, and the address bar wins.
    expect(shelfProps($this, User::factory()->create())['shelf']['state'])->toBe(Shelf::DEFAULT)
        ->and(shelfProps($this, $this->author, ['view' => 'cards'])['shelf']['state']['view'])->toBe('cards');

    $this->actingAs($this->author)->putJson('/shelf/books/current', ['state' => ['view' => 'cards']])->assertOk();
    expect(ShelfView::where('user_id', $this->author->id)->whereNull('name')->count())->toBe(1);
});

it('saves, lists, renames, replaces and deletes named views — only the person\'s own', function () {
    $other = User::factory()->create();

    $id = $this->actingAs($this->author)->postJson('/shelf/books/views', ['name' => 'Prépa table', 'state' => ['view' => 'table', 'q' => 'prepa']])
        ->assertCreated()->json('view.id');
    $this->actingAs($this->author)->postJson('/shelf/books/views', ['name' => 'Archived', 'state' => ['filter' => 'archived']])->assertCreated();
    // The same name again replaces it rather than adding one.
    $this->actingAs($this->author)->postJson('/shelf/books/views', ['name' => 'prépa TABLE', 'state' => ['view' => 'table', 'q' => 'prepa 2']])->assertCreated();

    $saved = shelfProps($this, $this->author)['shelf']['saved'];
    expect(array_column($saved, 'name'))->toBe(['Archived', 'prépa TABLE'])
        ->and($saved[1]['state']['q'])->toBe('prepa 2')
        ->and(shelfProps($this, $other)['shelf']['saved'])->toBe([]);

    $this->actingAs($this->author)->patchJson("/shelf/views/{$id}", ['name' => 'Prépa'])->assertOk()->assertJsonPath('view.name', 'Prépa');
    $this->actingAs($this->author)->patchJson("/shelf/views/{$id}", ['name' => 'archived'])->assertUnprocessable();

    // Another person can neither change nor delete it.
    $this->actingAs($other)->patchJson("/shelf/views/{$id}", ['name' => 'Mine now'])->assertNotFound();
    $this->actingAs($other)->deleteJson("/shelf/views/{$id}")->assertNotFound();

    $this->actingAs($this->author)->deleteJson("/shelf/views/{$id}")->assertOk();
    expect(ShelfView::find($id))->toBeNull();

    // A few, not hundreds.
    foreach (range(1, 7) as $n) {
        $this->actingAs($this->author)->postJson('/shelf/books/views', ['name' => "V{$n}", 'state' => []]);
    }
    $this->actingAs($this->author)->postJson('/shelf/books/views', ['name' => 'One too many', 'state' => []])->assertUnprocessable();
});

it('never shows another account\'s books, and the House shelf only to staff', function () {
    shelfBook($this->author, ['title' => 'Mine']);
    shelfBook(User::factory()->create(), ['title' => 'Theirs']);
    $house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test');
    shelfBook($house, ['title' => 'House book']);

    $props = shelfProps($this, $this->author, ['q' => 'e']);
    expect(shelfTitles($props))->not->toContain('Theirs')->not->toContain('House book')
        ->and(shelfProps($this, $this->author, ['filter' => 'house'])['shelf']['state']['filter'])->toBe('all')
        ->and(shelfTitles(shelfProps($this, $this->author, ['filter' => 'house'])))->toBe(['Mine']);

    $staff = platformAdmin();
    expect(shelfTitles(shelfProps($this, $staff, ['filter' => 'house'])))->toBe(['House book']);

    // The console's shelf, and its saved views, are staff-only.
    $this->actingAs($this->author)->putJson('/shelf/house/current', ['state' => []])->assertNotFound();
    $this->actingAs($staff)->putJson('/shelf/house/current', ['state' => ['view' => 'table']])->assertOk();
    $this->actingAs($staff)->get('/admin/house')->assertOk()->assertInertia(fn ($page) => $page
        ->where('shelf.state.view', 'table')
        ->has('books', 1)
        ->where('books.0.title', 'House book')
        ->where('counts.all', 1));
});

it('searches and groups the console\'s House catalog on the server', function () {
    $house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test');
    shelfBook($house, ['title' => 'Réussir 2', 'series_name' => 'Prépa', 'series_number' => 2]);
    shelfBook($house, ['title' => 'Réussir 1', 'series_name' => 'Prépa', 'series_number' => 1]);
    shelfBook($house, ['title' => 'Autre']);

    $this->actingAs(platformAdmin('support'))->get('/admin/house?q=reussir&group=series')->assertOk()->assertInertia(fn ($page) => $page
        ->has('books', 2)
        ->where('books.0.title', 'Réussir 1')
        ->where('books.1.title', 'Réussir 2')
        ->where('shelf.groups.0', ['name' => 'Prépa', 'count' => 2])
        ->where('can.manage', false));
});
