<?php

use App\Journey\BookType;
use App\Journey\Scaffolder;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * Book types and scaffolding.
 *
 * The configuration is the extensibility point the directive asks for, so
 * most of these assert properties that must hold for EVERY type rather than
 * checking one of them by hand — a new genre added to the config is then
 * covered the moment it exists.
 */

// --- The configuration itself -------------------------------------------

it('describes every configured book type completely', function () {
    // A half-filled entry would fail at the point somebody picks it, which
    // is in front of an author starting their first book.
    foreach (BookType::all() as $type) {
        expect($type->name())->not->toBeEmpty()
            ->and($type->blurb())->not->toBeEmpty()
            ->and($type->chapterPrompt())->not->toBeEmpty()
            ->and($type->words())->toHaveCount(2)
            ->and($type->chapters())->toHaveCount(2)
            ->and($type->agents())->not->toBeEmpty();
    }
});

it('keeps every range the right way round', function () {
    foreach (BookType::all() as $type) {
        [$lowWords, $highWords] = $type->words();
        [$lowChapters, $highChapters] = $type->chapters();

        expect($lowWords)->toBeLessThan($highWords)
            ->and($lowChapters)->toBeLessThanOrEqual($highChapters);
    }
});

it('only names agents that exist', function () {
    // A typo here means a book type that asks for a reviewer nobody can run.
    $known = array_column(App\Editorial\EditorialAgent::cases(), 'value');

    foreach (BookType::all() as $type) {
        foreach ($type->agents() as $agent) {
            $this->assertContains(
                $agent,
                $known,
                "[{$type->key}] names an unknown agent [{$agent}].",
            );
        }
    }
});

it('suggests a round number, not false precision', function () {
    // A target of 64,173 words implies research that does not exist. These
    // are published trade norms, and the suggestion should look like one.
    foreach (BookType::all() as $type) {
        expect($type->suggestedWords() % 1000)->toBe(0);
    }
});

it('suggests a length inside its own typical range', function () {
    foreach (BookType::all() as $type) {
        [$low, $high] = $type->words();

        expect($type->suggestedWords())->toBeGreaterThanOrEqual($low)
            ->and($type->suggestedWords())->toBeLessThanOrEqual($high);
    }
});

it('returns null for a type that does not exist', function () {
    expect(BookType::find('interpretive_dance'))->toBeNull()
        ->and(BookType::find(null))->toBeNull();
});

// --- Scaffolding --------------------------------------------------------

function scaffolded(string $key, ?int $chapters = null): Book
{
    $book = Book::factory()->for(User::factory())->create(['book_type' => $key]);
    $book->sections()->delete();

    return app(Scaffolder::class)->build($book, BookType::findOrFail($key), $chapters);
}

it('builds the number of chapters asked for', function () {
    expect(scaffolded('nonfiction', 12)->sections()->where('type', 'chapter')->count())
        ->toBe(12);
});

it('falls back to the type\'s suggestion', function () {
    $type = BookType::findOrFail('fiction');

    expect(scaffolded('fiction')->sections()->where('type', 'chapter')->count())
        ->toBe($type->suggestedChapters());
});

it('puts every chapter inside a part, for a type that uses them', function () {
    // A chapter orphaned beside the parts is a binder that looks broken.
    $book = scaffolded('nonfiction', 15);

    $orphans = $book->sections()
        ->where('type', 'chapter')
        ->whereNull('parent_id')
        ->count();

    expect($orphans)->toBe(0)
        ->and($book->sections()->where('type', 'part')->count())->toBeGreaterThan(0);
});

it('does not invent parts for a type that has none', function () {
    $book = scaffolded('childrens', 8);

    expect($book->sections()->where('type', 'part')->count())->toBe(0)
        ->and($book->sections()->where('type', 'chapter')->count())->toBe(8);
});

it('never leaves a part holding nothing', function () {
    // An empty part is a heading with no book under it.
    foreach (['nonfiction', 'fiction', 'academic', 'business'] as $key) {
        $book = scaffolded($key);

        foreach ($book->sections()->where('type', 'part')->get() as $part) {
            $this->assertGreaterThan(
                0,
                $part->children()->count(),
                "[{$key}] produced an empty part.",
            );
        }
    }
});

it('adds the front and back matter the type calls for', function () {
    $type = BookType::findOrFail('academic');
    $book = scaffolded('academic');

    expect($book->sections()->where('type', 'front_matter')->count())
        ->toBe(count($type->frontMatter()))
        ->and($book->sections()->where('type', 'back_matter')->count())
        ->toBe(count($type->backMatter()));
});

it('orders front matter, chapters and back matter correctly', function () {
    // Position order is binder order, and a conclusion before chapter one
    // would be visible on the first screen an author sees.
    $book = scaffolded('nonfiction', 10);

    $positions = $book->sections()->orderBy('position')->get()
        ->groupBy('type')
        ->map(fn ($group) => $group->pluck('position'));

    expect($positions['front_matter']->max())
        ->toBeLessThan($positions['chapter']->min())
        ->and($positions['back_matter']->min())
        ->toBeGreaterThan($positions['chapter']->max());
});

it('opens each chapter with a prompt rather than invented prose', function () {
    // The scaffold is a place to write. Filling it with generated text would
    // be writing somebody's book before they have said anything about it.
    $book = scaffolded('memoir', 6);
    $type = BookType::findOrFail('memoir');

    foreach ($book->sections()->where('type', 'chapter')->get() as $chapter) {
        expect($chapter->plainText())->toBe($type->chapterPrompt());
    }
});

it('refuses an absurd chapter count rather than building it', function () {
    // Clamped, not rejected: somebody typing 5000 means "a lot", and failing
    // the whole creation over it would be worse than giving them 60.
    expect(scaffolded('fiction', 5000)->sections()->where('type', 'chapter')->count())
        ->toBe(60);

    expect(scaffolded('fiction', 0)->sections()->where('type', 'chapter')->count())
        ->toBe(1);
});

it('scaffolds every configured type without error', function () {
    // The guard that matters when a genre is added: it is covered the moment
    // it exists in the config, with no new test to remember to write.
    foreach (BookType::all() as $type) {
        $book = scaffolded($type->key);

        $this->assertGreaterThan(
            0,
            $book->sections()->count(),
            "[{$type->key}] scaffolded to nothing.",
        );
    }
});

// --- Journey state ------------------------------------------------------

it('starts a book in the first stage', function () {
    // Through the relation rather than the factory: the factory sets every
    // column explicitly and so never exercises the database defaults, which
    // are what a book created by the application actually gets.
    $book = User::factory()->create()->books()->create(['title' => 'A Book']);

    expect($book->fresh()->journey_stage)->toBe('discover');
});

it('defaults to the author writing it themselves', function () {
    // The assistance level somebody has not chosen should be the one that
    // does least on their behalf.
    $book = User::factory()->create()->books()->create(['title' => 'A Book']);

    expect($book->fresh()->assistance)->toBe('author_led');
});

it('moves between stages', function () {
    $book = Book::factory()->for(User::factory())->create();

    foreach (Book::JOURNEY_STAGES as $stage) {
        $book->update(['journey_stage' => $stage]);

        expect($book->fresh()->journey_stage)->toBe($stage);
    }
});

it('refuses a stage that is not part of the journey', function () {
    $book = Book::factory()->for(User::factory())->create();

    expect(fn () => $book->update(['journey_stage' => 'procrastinate']))
        ->toThrow(QueryException::class);
});

it('refuses an assistance level that does not exist', function () {
    $book = Book::factory()->for(User::factory())->create();

    expect(fn () => $book->update(['assistance' => 'write_it_all_for_me']))
        ->toThrow(QueryException::class);
});

it('belongs to the author studio unless told otherwise', function () {
    $book = User::factory()->create()->books()->create(['title' => 'A Book']);

    expect($book->fresh()->studio)->toBe('author');
});

it('refuses an unknown studio', function () {
    $book = Book::factory()->for(User::factory())->create();

    expect(fn () => $book->update(['studio' => 'woodworking']))
        ->toThrow(QueryException::class);
});

it('remembers where the author stopped', function () {
    // What resuming depends on: reopening should return to the chapter they
    // were in, not the top of the binder.
    $book = Book::factory()->for(User::factory())->create();
    $section = ManuscriptSection::factory()->for($book)->create();

    $book->forceFill(['last_section_id' => $section->id])->save();

    expect($book->fresh()->last_section_id)->toBe($section->id);
});

it('forgets the last section when it is deleted, rather than dangling', function () {
    $book = Book::factory()->for(User::factory())->create();
    $section = ManuscriptSection::factory()->for($book)->create();

    $book->forceFill(['last_section_id' => $section->id])->save();
    $section->forceDelete();

    expect($book->fresh()->last_section_id)->toBeNull();
});
