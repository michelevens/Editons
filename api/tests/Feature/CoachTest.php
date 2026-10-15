<?php

use App\Billing\CreditLedger;
use App\Journey\Coach;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use Illuminate\Support\Facades\File;

/**
 * Coaching.
 *
 * The directive's middle assistance level. These tests hold it to the two
 * promises that make it worth having: that choosing it changes what the
 * author sees, and that every note is an observation about the manuscript
 * rather than encouragement.
 *
 * They also hold it to costing nothing. Coaching that quietly spent credits
 * as somebody typed would be a billing surprise, and the reason this is
 * computed rather than generated.
 */
function coachedBook(User $user, array $attributes = []): Book
{
    return $user->books()->create(array_merge([
        'title' => 'A Coached Book',
        'book_type' => 'nonfiction',
        'assistance' => 'coach',
    ], $attributes));
}

function chapter(Book $book, string $title, int $words, int $position): ManuscriptSection
{
    $section = $book->sections()->create([
        'type' => 'chapter',
        'title' => $title,
        'position' => $position,
        'content' => ['type' => 'doc', 'content' => []],
    ]);

    $section->forceFill(['word_count' => $words])->save();

    return $section;
}

it('says nothing to an author who did not ask', function () {
    // The point of the assistance level: "I write" means the product stays
    // out of the way, and that has to be observable.
    $user = User::factory()->create();
    $book = coachedBook($user, ['assistance' => 'author_led']);

    chapter($book, 'One', 2000, 100);

    $coaching = $this->actingAs($user)
        ->getJson("/books/{$book->id}/journey")
        ->json('coaching');

    expect($coaching)->toBe([]);
});

it('speaks to an author who asked', function () {
    $user = User::factory()->create();
    $book = coachedBook($user);

    $coaching = $this->actingAs($user)
        ->getJson("/books/{$book->id}/journey")
        ->json('coaching');

    expect($coaching)->not->toBeEmpty();
});

it('starts speaking the moment the level changes', function () {
    // Choosing the level is what turns it on, with no other step.
    $user = User::factory()->create();
    $book = coachedBook($user, ['assistance' => 'author_led']);

    expect($this->actingAs($user)->getJson("/books/{$book->id}/journey")->json('coaching'))
        ->toBe([]);

    $coaching = $this->actingAs($user)
        ->patchJson("/books/{$book->id}/assistance", ['assistance' => 'coach'])
        ->json('coaching');

    expect($coaching)->not->toBeEmpty();
});

it('costs nothing', function () {
    // The constraint that shaped the whole class.
    $user = User::factory()->subscribed('studio', 100)->create();
    $book = coachedBook($user);

    chapter($book, 'One', 3000, 100);

    $this->actingAs($user)->getJson("/books/{$book->id}/journey")->assertOk();

    expect(app(CreditLedger::class)->balanceFor($user))->toBe(100);
});

it('gives every note something it observed', function () {
    // A coach that says "keep going!" is not coaching. Asserted across many
    // shapes of book rather than one, so a vague note added later is caught.
    $user = User::factory()->create();

    $shapes = [
        fn ($book) => null,
        fn ($book) => chapter($book, 'Only', 80, 100),
        function ($book) {
            foreach (range(1, 6) as $n) {
                chapter($book, "Chapter {$n}", $n === 3 ? 12000 : 1500, $n * 100);
            }
        },
        function ($book) {
            foreach (range(1, 30) as $n) {
                chapter($book, "Chapter {$n}", 900, $n * 100);
            }
        },
    ];

    foreach ($shapes as $index => $build) {
        $book = coachedBook($user, ['title' => "Shape {$index}"]);
        $build($book);

        foreach (app(Coach::class)->forBook($book->fresh()) as $note) {
            expect($note['note'])->not->toBeEmpty()
                ->and($note['detail'])->not->toBeEmpty()
                ->and($note['key'])->not->toBeEmpty();

            // Encouragement has no numbers in it. The exception is the
            // note whose observation is an absence — "nothing written yet"
            // is a fact about the manuscript, and a figure would be a zero.
            if ($note['key'] !== 'unstarted') {
                $this->assertMatchesRegularExpression(
                    '/\d/',
                    $note['note'].$note['detail'],
                    "[{$note['key']}] names no figure, so it is encouragement rather than an observation.",
                );
            }
        }
    }
});

it('never returns more than it was asked for', function () {
    // A coach returning fourteen observations has made the job harder.
    $user = User::factory()->create();
    $book = coachedBook($user, ['target_words' => 20000]);

    foreach (range(1, 40) as $n) {
        chapter($book, "Chapter {$n}", $n === 1 ? 40000 : 60, $n * 100);
    }

    // At most the cap. Asserting exactly four would be asserting that this
    // contrived book happens to trip four distinct observations, which is a
    // fact about the fixture rather than about the cap.
    expect(count(app(Coach::class)->forBook($book->fresh())))
        ->toBeLessThanOrEqual(4);
});

it('notices a book nobody has started', function () {
    $user = User::factory()->create();
    $book = coachedBook($user);

    chapter($book, 'One', 0, 100);

    expect(collect(app(Coach::class)->forBook($book))->pluck('key'))
        ->toContain('unstarted');
});

it('notices one chapter dwarfing the others', function () {
    $user = User::factory()->create();
    $book = coachedBook($user);

    foreach (range(1, 5) as $n) {
        chapter($book, "Chapter {$n}", 1500, $n * 100);
    }

    chapter($book, 'The Long One', 20000, 600);

    $notes = collect(app(Coach::class)->forBook($book->fresh()));

    expect($notes->pluck('key'))->toContain('uneven')
        ->and($notes->firstWhere('key', 'uneven')['note'])->toContain('The Long One');
});

it('notices too many chapters for the kind of book', function () {
    $user = User::factory()->create();
    $book = coachedBook($user);

    // Nonfiction is typically 10-20.
    foreach (range(1, 34) as $n) {
        chapter($book, "Chapter {$n}", 1200, $n * 100);
    }

    expect(collect(app(Coach::class)->forBook($book->fresh()))->pluck('key'))
        ->toContain('many_chapters');
});

it('calls a length typical rather than researched', function () {
    // config/booktypes.php holds published trade norms, not market data, and
    // nothing here has researched this author's market.
    $user = User::factory()->create();
    $book = coachedBook($user);

    foreach (range(1, 34) as $n) {
        chapter($book, "Chapter {$n}", 1200, $n * 100);
    }

    $note = collect(app(Coach::class)->forBook($book->fresh()))
        ->firstWhere('key', 'many_chapters');

    expect(strtolower($note['detail']))->toContain('typically');
});

it('says nothing about a target the author never set', function () {
    // Progress against a number nobody chose is not progress.
    $user = User::factory()->create();
    $book = coachedBook($user, ['target_words' => null]);

    foreach (range(1, 12) as $n) {
        chapter($book, "Chapter {$n}", 1500, $n * 100);
    }

    $keys = collect(app(Coach::class)->forBook($book->fresh()))->pluck('key');

    expect($keys)->not->toContain('over_target')
        ->and($keys)->not->toContain('under_target');
});

it('warns when the pace will overshoot a target', function () {
    $user = User::factory()->create();
    $book = coachedBook($user, ['target_words' => 20000]);

    // 10 chapters at 4,000 projects to 40,000 against a 20,000 target.
    foreach (range(1, 5) as $n) {
        chapter($book, "Chapter {$n}", 4000, $n * 100);
    }
    foreach (range(6, 10) as $n) {
        chapter($book, "Chapter {$n}", 0, $n * 100);
    }

    $book->forceFill(['word_count' => 20000])->save();

    expect(collect(app(Coach::class)->forBook($book->fresh()))->pluck('key'))
        ->toContain('over_target');
});

// --- One chapter at a time ----------------------------------------------

it('tells an author a blank chapter is blank', function () {
    $user = User::factory()->create();
    $book = coachedBook($user);
    $section = chapter($book, 'Empty', 0, 100);

    expect(collect(app(Coach::class)->forSection($section))->pluck('key'))
        ->toContain('empty');
});

it('calls a very short chapter a note', function () {
    $user = User::factory()->create();
    $book = coachedBook($user);
    $section = chapter($book, 'Barely There', 40, 100);

    expect(collect(app(Coach::class)->forSection($section))->pluck('key'))
        ->toContain('stub');
});

it('compares a chapter to the book rather than to a norm', function () {
    $user = User::factory()->create();
    $book = coachedBook($user);

    foreach (range(1, 4) as $n) {
        chapter($book, "Chapter {$n}", 1500, $n * 100);
    }

    $long = chapter($book, 'The Long One', 30000, 500);

    expect(collect(app(Coach::class)->forSection($long->fresh()))->pluck('key'))
        ->toContain('long');
});

it('says nothing about a chapter in a book too small to compare', function () {
    // Two chapters give no median worth comparing against, and inventing
    // one would make the note meaningless.
    $user = User::factory()->create();
    $book = coachedBook($user);

    $one = chapter($book, 'One', 5000, 100);
    chapter($book, 'Two', 400, 200);

    expect(collect(app(Coach::class)->forSection($one->fresh()))->pluck('key'))
        ->not->toContain('long');
});

it('is quiet about an ordinary chapter', function () {
    // The most important case: a coach that always has something to say is
    // noise, and gets ignored along with the notes that mattered.
    $user = User::factory()->create();
    $book = coachedBook($user);

    foreach (range(1, 5) as $n) {
        chapter($book, "Chapter {$n}", 1500, $n * 100);
    }

    $ordinary = $book->sections()->where('title', 'Chapter 3')->first();

    expect(app(Coach::class)->forSection($ordinary))->toBe([]);
});

it('speaks up about a book that looks finished but is far short', function () {
    // The case that was silent: nothing blank, nothing outstanding, and a
    // fraction of the target. Every other signal in the product says there
    // is nothing left to do, which is when the author most needs telling.
    $user = User::factory()->create();
    $book = coachedBook($user, ['target_words' => 90000]);

    foreach (range(1, 20) as $n) {
        chapter($book, "Chapter {$n}", 240, $n * 100);
    }

    $book->forceFill(['word_count' => 4800])->save();

    expect(collect(app(Coach::class)->forBook($book->fresh()))->pluck('key'))
        ->toContain('short_of_target');
});

it('says nothing when a finished book is near its target', function () {
    $user = User::factory()->create();
    $book = coachedBook($user, ['target_words' => 60000]);

    foreach (range(1, 20) as $n) {
        chapter($book, "Chapter {$n}", 3000, $n * 100);
    }

    $book->forceFill(['word_count' => 60000])->save();

    expect(collect(app(Coach::class)->forBook($book->fresh()))->pluck('key'))
        ->not->toContain('short_of_target');
});

// --- Coaching while writing ---------------------------------------------

it('coaches the chapter the author is actually in', function () {
    // forSection() was written, tested, and had no caller for its whole
    // life: the book-level notes shipped while the per-chapter ones — the
    // only kind useful while writing — reached nobody.
    $user = User::factory()->create();
    $book = coachedBook($user);

    foreach (range(1, 5) as $n) {
        chapter($book, "Chapter {$n}", 2000, $n * 100);
    }

    $stub = chapter($book, 'Barely There', 40, 600);

    $coaching = $this->actingAs($user)
        ->getJson("/sections/{$stub->id}/findings")
        ->assertOk()
        ->json('coaching');

    expect(collect($coaching)->pluck('key'))->toContain('stub');
});

it('stays silent in the editor for an author who did not ask', function () {
    $user = User::factory()->create();
    $book = coachedBook($user, ['assistance' => 'author_led']);
    $section = chapter($book, 'Empty', 0, 100);

    expect($this->actingAs($user)->getJson("/sections/{$section->id}/findings")->json('coaching'))
        ->toBe([]);
});

it('says nothing about an ordinary chapter while writing it', function () {
    // The case that decides whether this is help or noise.
    $user = User::factory()->create();
    $book = coachedBook($user);

    foreach (range(1, 5) as $n) {
        chapter($book, "Chapter {$n}", 1500, $n * 100);
    }

    $ordinary = $book->sections()->where('title', 'Chapter 3')->first();

    expect($this->actingAs($user)->getJson("/sections/{$ordinary->id}/findings")->json('coaching'))
        ->toBe([]);
});

it('costs nothing to coach a chapter', function () {
    $user = User::factory()->subscribed('studio', 100)->create();
    $book = coachedBook($user);
    $section = chapter($book, 'A Chapter', 40, 100);

    $this->actingAs($user)->getJson("/sections/{$section->id}/findings")->assertOk();

    expect(app(CreditLedger::class)->balanceFor($user))->toBe(100);
});

it('keeps every Coach method wired to something', function () {
    /*
     * The recurring failure this guards.
     *
     * Four times in this codebase a method has been written, tested, and
     * never called: forSection(), the journey endpoints, Quick Start's
     * route, and the book type's agent list. A unit test passes either way,
     * which is exactly why it keeps happening.
     */
    $app = collect(File::allFiles(app_path()))
        ->reject(fn ($file) => str_contains($file->getPathname(), 'Journey'.DIRECTORY_SEPARATOR.'Coach.php'))
        ->map(fn ($file) => $file->getContents())
        ->implode("\n");

    foreach (['forBook', 'forSection'] as $method) {
        $this->assertStringContainsString(
            "->{$method}(",
            $app,
            "Coach::{$method}() has no caller, so nobody ever sees what it produces.",
        );
    }
});
