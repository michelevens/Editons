<?php

use App\Billing\CreditLedger;
use App\Editorial\BookDirector;
use App\Editorial\ManuscriptMemory;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;

/**
 * The Book Director, and the memory it reads.
 *
 * The claim being tested: that this sees things no per-section agent can.
 * Every test here builds a book whose problem is invisible from inside any
 * one chapter — a part twice the weight of its neighbour, eleven chapters
 * opening alike, a promise pointing at a chapter that was cut — and asserts
 * the Director names it.
 *
 * The second claim, equally important: that it stays quiet about a book with
 * nothing wrong, and about a book too early to judge. A structural reviewer
 * that always has something to say gets ignored along with the findings that
 * mattered.
 */
function directedBook(User $user, array $attributes = []): Book
{
    return $user->books()->create(array_merge([
        'title' => 'A Book To Direct',
        'book_type' => 'nonfiction',
    ], $attributes));
}

function directedChapter(
    Book $book,
    string $title,
    int $words,
    int $position,
    string $text = 'An ordinary paragraph of prose that says something.',
    ?ManuscriptSection $part = null,
): ManuscriptSection {
    $section = $book->sections()->create([
        'type' => 'chapter',
        'title' => $title,
        'position' => $position,
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => $title]]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
        ]],
    ]);

    $section->forceFill([
        'word_count' => $words,
        'parent_id' => $part?->id,
    ])->save();

    return $section;
}

function directedPart(Book $book, string $title, int $position): ManuscriptSection
{
    return $book->sections()->create([
        'type' => 'part',
        'title' => $title,
        'position' => $position,
        'content' => ['type' => 'doc', 'content' => []],
    ]);
}

/** @return array<int, string> the titles of whatever the Director reported */
function directorTitles(Book $book): array
{
    return array_map(
        fn ($finding) => $finding->title,
        app(BookDirector::class)->review($book->fresh()),
    );
}

// --- The memory ----------------------------------------------------------

it('indexes the book without carrying the book', function () {
    // The design constraint: memory holds one summarised row per chapter, so
    // a 90,000-word manuscript does not become a 90,000-word object. The
    // opening is a sentence, not the chapter.
    $user = User::factory()->create();
    $book = directedBook($user);

    directedChapter($book, 'One', 4000, 10, str_repeat('A long paragraph of prose. ', 400));

    $memory = ManuscriptMemory::of($book->fresh());

    expect($memory->chapters[0]['words'])->toBe(4000)
        ->and(mb_strlen($memory->chapters[0]['opening']))->toBeLessThan(400);
});

it('reads a chapter opening as its first prose, not its heading', function () {
    // Treating the title as the opening would make every chapter look like
    // it began by announcing itself, and would break the repeated-opening
    // check entirely.
    $user = User::factory()->create();
    $book = directedBook($user);

    directedChapter($book, 'The Professional Opportunity', 500, 10, 'Demand has never been greater.');

    $memory = ManuscriptMemory::of($book->fresh());

    expect($memory->chapters[0]['opening'])->toBe('Demand has never been greater.');
});

it('attributes chapters to the part that holds them', function () {
    $user = User::factory()->create();
    $book = directedBook($user);

    $part = directedPart($book, 'Part One', 5);
    directedChapter($book, 'A', 1000, 10, 'Text.', $part);
    directedChapter($book, 'B', 1500, 20, 'Text.', $part);

    $memory = ManuscriptMemory::of($book->fresh());

    expect($memory->parts[0]['chapters'])->toBe(2)
        ->and($memory->parts[0]['words'])->toBe(2500);
});

it('keeps chapters in reading order', function () {
    $user = User::factory()->create();
    $book = directedBook($user);

    directedChapter($book, 'Third', 500, 30);
    directedChapter($book, 'First', 500, 10);
    directedChapter($book, 'Second', 500, 20);

    expect(array_column(ManuscriptMemory::of($book->fresh())->chapters, 'title'))
        ->toBe(['First', 'Second', 'Third']);
});

it('summarises the book small enough to send somewhere', function () {
    // The outline is what would go into a provider prompt when one is wired
    // in. If it grows with the manuscript it reintroduces the cost the index
    // exists to avoid.
    $user = User::factory()->create();
    $book = directedBook($user);

    foreach (range(1, 20) as $n) {
        directedChapter($book, "Chapter {$n}", 4000, $n * 10, str_repeat('Prose. ', 500));
    }

    expect(mb_strlen(ManuscriptMemory::of($book->fresh())->outline()))
        ->toBeLessThan(2000);
});

// --- Staying quiet -------------------------------------------------------

it('says nothing about a book barely started', function () {
    // Two chapters have no shape to comment on, and inventing structural
    // findings is how an author learns to ignore the panel.
    $user = User::factory()->create();
    $book = directedBook($user);

    directedChapter($book, 'One', 900, 10);
    directedChapter($book, 'Two', 900, 20);

    expect(directorTitles($book))->toBe([]);
});

it('says nothing about an evenly paced book', function () {
    $user = User::factory()->create();
    $book = directedBook($user);

    // Genuinely different openings. "A distinct opening {$n}." is NOT
    // different — the first three words are identical, and the Director was
    // right to say so when this fixture used it.
    $openings = [
        'Demand has never been greater than now.',
        'Referrals arrive before any advertising does.',
        'Costing a visit properly takes an afternoon.',
        'Your first hire changes everything at once.',
        'Measuring outcomes is uncomfortable and useful.',
        'Growth multiplies whatever is already true.',
        'Undercharging compounds quietly over years.',
        'Insurance is the cheap part of risk.',
        'Telehealth replaced the drive, not the clinic.',
        'Succession begins long before anybody leaves.',
        'Write the disagreement down while you agree.',
        'Boundaries only hold when they are structural.',
    ];

    foreach ($openings as $index => $opening) {
        directedChapter($book, 'Chapter '.($index + 1), 2000, ($index + 1) * 10, $opening);
    }

    expect(directorTitles($book))->toBe([]);
});

// --- What only the whole book shows --------------------------------------

it('notices a part carrying far more than the lightest', function () {
    // Invisible from inside any chapter in either part.
    $user = User::factory()->create();
    $book = directedBook($user);

    $heavy = directedPart($book, 'Part One', 5);
    $light = directedPart($book, 'Part Two', 105);

    foreach (range(1, 5) as $n) {
        directedChapter($book, "Heavy {$n}", 4000, $n * 10, "Distinct opening {$n}.", $heavy);
    }
    foreach (range(6, 9) as $n) {
        directedChapter($book, "Light {$n}", 300, $n * 10 + 60, "Another distinct opening {$n}.", $light);
    }

    expect(implode(' ', directorTitles($book)))
        ->toContain('"Part One" carries much more than the lightest part');
});

it('compares exactly two parts, which a median could not', function () {
    // A median taken at the middle index picks the larger of two, so the
    // commonest imbalance there is was the one case this could not see.
    $user = User::factory()->create();
    $book = directedBook($user);

    $heavy = directedPart($book, 'Big', 5);
    $light = directedPart($book, 'Small', 105);

    foreach (range(1, 3) as $n) {
        directedChapter($book, "Big {$n}", 5000, $n * 10, "Opening {$n} here.", $heavy);
    }
    directedChapter($book, 'Small one', 400, 110, 'A different opening entirely.', $light);

    expect(implode(' ', directorTitles($book)))->toContain('"Big" carries much more');
});

it('notices the same opening gesture across chapters', function () {
    // The one vantage point the writer never has: meeting the openings in
    // sequence.
    $user = User::factory()->create();
    $book = directedBook($user);

    foreach (range(1, 5) as $n) {
        directedChapter($book, "Chapter {$n}", 2000, $n * 10, 'The practice that lasts is built slowly.');
    }

    expect(implode(' ', directorTitles($book)))
        ->toContain('5 chapters open with "the practice that…"');
});

it('does not call two similar openings a pattern', function () {
    $user = User::factory()->create();
    $book = directedBook($user);

    directedChapter($book, 'A', 2000, 10, 'The practice that lasts is built slowly.');
    directedChapter($book, 'B', 2000, 20, 'The practice that lasts is built carefully.');
    directedChapter($book, 'C', 2000, 30, 'Something else entirely begins here.');
    directedChapter($book, 'D', 2000, 40, 'A fourth and different opening line.');

    expect(implode(' ', directorTitles($book)))->not->toContain('open with');
});

it('notices a promise pointing at a chapter that does not exist', function () {
    $user = User::factory()->create();
    $book = directedBook($user);

    foreach (range(1, 5) as $n) {
        directedChapter($book, "Chapter {$n}", 2000, $n * 10, "A distinct opening {$n}.");
    }

    directedChapter($book, 'The Promise', 2000, 60, 'We return to this properly in Chapter 19.');

    expect(implode(' ', directorTitles($book)))
        ->toContain('to a chapter 19 that does not exist');
});

it('reports one broken reference once, not once per chapter', function () {
    // Five chapters pointing at a deleted chapter 44 is one problem with one
    // fix. Reported five times it buries every other finding.
    $user = User::factory()->create();
    $book = directedBook($user);

    foreach (range(1, 5) as $n) {
        directedChapter($book, "Chapter {$n}", 2000, $n * 10, "Distinct line {$n}. See Chapter 44 for more.");
    }

    $stale = array_values(array_filter(
        directorTitles($book),
        fn (string $t) => str_contains($t, 'chapter 44'),
    ));

    expect($stale)->toHaveCount(1)
        ->and($stale[0])->toContain('5 chapters refer');
});

it('leaves a reference that resolves alone', function () {
    $user = User::factory()->create();
    $book = directedBook($user);

    foreach (range(1, 8) as $n) {
        directedChapter($book, "Chapter {$n}", 2000, $n * 10, "A distinct opening {$n}.");
    }

    directedChapter($book, 'Pointer', 2000, 90, 'This is covered in Chapter 3.');

    expect(implode(' ', directorTitles($book)))->not->toContain('does not exist');
});

it('notices stub chapters across the book', function () {
    $user = User::factory()->create();
    $book = directedBook($user);

    foreach (range(1, 5) as $n) {
        directedChapter($book, "Full {$n}", 2000, $n * 10, "A distinct opening {$n}.");
    }

    directedChapter($book, 'Barely There', 40, 60, 'Almost nothing written here yet.');

    expect(implode(' ', directorTitles($book)))->toContain('1 chapter under 150 words');
});

it('calls a chapter count typical rather than researched', function () {
    // config/booktypes.php holds published trade norms. Nothing here has
    // researched this book's market, and the wording must not imply it has.
    $user = User::factory()->create();
    $book = directedBook($user);

    foreach (range(1, 34) as $n) {
        directedChapter($book, "Chapter {$n}", 1200, $n * 10, "A distinct opening {$n}.");
    }

    $findings = app(BookDirector::class)->review($book->fresh());
    $shape = collect($findings)->first(fn ($f) => str_contains($f->title, 'chapters is many'));

    expect($shape)->not->toBeNull()
        ->and(strtolower($shape->detail))->toContain('typically');
});

// --- The endpoint --------------------------------------------------------

it('reviews the whole book over HTTP', function () {
    $user = User::factory()->create();
    $book = directedBook($user);

    foreach (range(1, 5) as $n) {
        directedChapter($book, "Chapter {$n}", 2000, $n * 10, 'The practice that lasts is built slowly.');
    }

    $body = $this->actingAs($user)->getJson("/books/{$book->id}/direct")->assertOk()->json();

    expect($body['enough_to_review'])->toBeTrue()
        ->and($body['findings'])->not->toBeEmpty()
        ->and($body['findings'][0])->toHaveKeys(['severity', 'title', 'detail']);
});

it('distinguishes a clean book from one too early to judge', function () {
    // An empty findings list means two very different things and the panel
    // must not show the wrong one.
    $user = User::factory()->create();
    $early = directedBook($user, ['title' => 'Barely Begun']);
    directedChapter($early, 'One', 500, 10);

    $body = $this->actingAs($user)->getJson("/books/{$early->id}/direct")->json();

    expect($body['findings'])->toBe([])
        ->and($body['enough_to_review'])->toBeFalse();
});

it('costs nothing', function () {
    // The product prices book_review at 40 credits and has never spent it.
    // Charging that for heuristics over an index would be charging for
    // arithmetic, so this path is free and the priced one stays reserved.
    $user = User::factory()->subscribed('studio', 100)->create();
    $book = directedBook($user);

    foreach (range(1, 5) as $n) {
        directedChapter($book, "Chapter {$n}", 2000, $n * 10, "A distinct opening {$n}.");
    }

    $this->actingAs($user)->getJson("/books/{$book->id}/direct")->assertOk();

    expect(app(CreditLedger::class)->balanceFor($user))->toBe(100);
});

it('will not read a stranger\'s book', function () {
    $owner = User::factory()->create();
    $book = directedBook($owner);

    $this->actingAs(User::factory()->create())
        ->getJson("/books/{$book->id}/direct")
        ->assertForbidden();
});

it('requires signing in', function () {
    $book = directedBook(User::factory()->create());

    $this->getJson("/books/{$book->id}/direct")->assertUnauthorized();
});
