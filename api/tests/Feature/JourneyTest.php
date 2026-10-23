<?php

use App\Editorial\EditorialAgent;
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
    $known = array_column(EditorialAgent::cases(), 'value');

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

it('totals the scaffold words on the book', function () {
    // The chapters carry their prompts; the shelf reads the book's total,
    // which was left at 0.
    $book = scaffolded('nonfiction', 4);

    expect($book->word_count)->toBeGreaterThan(0)
        ->and($book->word_count)->toBe((int) $book->sections()->sum('word_count'));
});

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

it('orders the editorial agents for the kind of book', function () {
    // config/booktypes.php has always named the reviewers that matter most
    // for each type, and nothing consumed the list: the editor received the
    // enum's own order for every book, so the field's docblock described
    // something no code did.
    $user = User::factory()->create();
    $book = $user->books()->create(['title' => 'A Cookbook', 'book_type' => 'cookbook']);

    $agents = $this->actingAs($user)
        ->get("/books/{$book->id}/edit")
        ->viewData('page')['props']['agents'];

    expect(array_column(array_slice($agents, 0, 3), 'value'))
        ->toBe(BookType::findOrFail('cookbook')->agents());
});

it('still offers every agent, whatever the book is', function () {
    // Ordering, never filtering. An author who wants the accessibility check
    // on a memoir should not have to change the book's type to reach it.
    //
    // Asserted against the catalogue rather than by loading the editor once
    // per type: the page-rendering version of this took so long across 15
    // types that it timed out, and the claim is about the catalogue anyway.
    foreach (BookType::all() as $type) {
        expect(EditorialAgent::catalogue($type->agents()))->toHaveCount(
            count(EditorialAgent::cases()),
            "[{$type->key}] offers fewer agents than exist.",
        );
    }
});

it('never suggests a scaffold nobody asked for', function () {
    /*
     * The bug this caught, which I introduced.
     *
     * suggestedChapters() takes the midpoint of the range, so adding
     * Devotional as [30, 365] made its default 198 chapters: a new author
     * choosing that type would have watched the app build 198 sections
     * before writing a word.
     *
     * A default an author has to delete from is worse than one they add to,
     * so every type's suggestion stays within what somebody would plausibly
     * accept untouched.
     */
    foreach (BookType::all() as $type) {
        expect($type->suggestedChapters())->toBeLessThanOrEqual(
            60,
            "[{$type->key}] would scaffold {$type->suggestedChapters()} chapters by default.",
        );
    }
});

it('marks which agents suit this book', function () {
    $user = User::factory()->create();
    $book = $user->books()->create(['title' => 'A Life', 'book_type' => 'biography']);

    $agents = collect($this->actingAs($user)
        ->get("/books/{$book->id}/edit")
        ->viewData('page')['props']['agents']);

    expect($agents->where('suited', true)->pluck('value')->all())
        ->toBe(BookType::findOrFail('biography')->agents());
});

it('falls back to the plain order for a book with no type', function () {
    $user = User::factory()->create();
    $book = $user->books()->create(['title' => 'Untyped', 'book_type' => null]);

    $agents = $this->actingAs($user)
        ->get("/books/{$book->id}/edit")
        ->viewData('page')['props']['agents'];

    expect(array_column($agents, 'value'))
        ->toBe(array_column(EditorialAgent::cases(), 'value'));
});

it('asks every genre its own three questions', function () {
    // The point of per-genre questions: a generic "What is it about?" gets a
    // generic answer. Property-based, so a genre added later is covered the
    // moment it exists.
    $seen = [];

    foreach (BookType::all() as $type) {
        $questions = $type->questions();

        expect($questions)->toHaveKeys(['premise', 'audience', 'promise']);

        foreach ($questions as $field => $question) {
            expect(trim($question))->not->toBeEmpty("[{$type->key}.{$field}] is empty.");

            $this->assertStringEndsWith(
                '?',
                $question,
                "[{$type->key}.{$field}] is not phrased as a question.",
            );
        }

        $seen[] = $questions['premise'];
    }

    // Distinct wording, not the same sentence fifteen times with a different
    // noun — that would be the generic version wearing a costume.
    expect(count(array_unique($seen)))->toBe(count($seen));
});

it('never asks a genre the neutral question by accident', function () {
    // The fallback exists for a book with no type. A configured genre
    // reaching it means its questions block is missing.
    foreach (BookType::all() as $type) {
        expect($type->questions())->not->toBe(
            BookType::NEUTRAL_QUESTIONS,
            "[{$type->key}] falls back to the neutral wording.",
        );
    }
});

it('asks something sensible when the book has no type', function () {
    $user = User::factory()->create();
    $book = $user->books()->create(['title' => 'Untyped', 'book_type' => null]);

    $questions = $this->actingAs($user)
        ->getJson("/books/{$book->id}/journey")
        ->json('questions');

    expect($questions)->toBe(BookType::NEUTRAL_QUESTIONS);
});

it('phrases the questions for the kind of book', function () {
    $user = User::factory()->create();
    $book = $user->books()->create(['title' => 'Recipes', 'book_type' => 'cookbook']);

    $questions = $this->actingAs($user)
        ->getJson("/books/{$book->id}/journey")
        ->json('questions');

    expect($questions['premise'])->toBe(BookType::findOrFail('cookbook')->questions()['premise'])
        ->and($questions['premise'])->not->toBe(BookType::NEUTRAL_QUESTIONS['premise']);
});

it('keeps a premise given while making the book', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/books', [
        'title' => 'Weeknight Cooking',
        'book_type' => 'cookbook',
        'premise' => 'Thirty recipes you can finish in the time rice takes.',
    ])->assertRedirect();

    $book = $user->books()->first();

    expect($book->premise)->toBe('Thirty recipes you can finish in the time rice takes.')
        ->and($book->journey_stage)->toBe('plan');
});

it('leaves a book without a premise in Discover', function () {
    // The stage must not claim Discover is behind the author when the
    // question that stage asks is still unanswered.
    $user = User::factory()->create();

    $this->actingAs($user)->post('/books', [
        'title' => 'Untitled Cookbook',
        'book_type' => 'cookbook',
    ]);

    expect($user->books()->first()->journey_stage)->toBe('discover');
});
