<?php

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\ManuscriptVersion;
use App\Models\User;
use App\Services\ManuscriptWriter;

/**
 * The prototype generates its daily chart from a seeded random number and
 * labels it "sample history". It does not have to: every save writes a
 * version carrying a word count and a timestamp, so the real curve is already
 * recorded. These tests exist to keep it real.
 */
function writeChapter(ManuscriptSection $section, string $text): void
{
    app(ManuscriptWriter::class)->save(
        section: $section,
        content: ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => $text]],
        ]]],
        reason: 'manual',
    );
}

function analyticsFor(User $user, Book $book): array
{
    return test()->actingAs($user)
        ->getJson("/books/{$book->id}/analytics")
        ->assertOk()
        ->json();
}

it('says plainly when a book has no version history', function () {
    // Seeded or imported books have no snapshots. Drawing a plausible curve
    // for them would be the mock-data trap.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $data = analyticsFor($user, $book);

    expect($data['has_history'])->toBeFalse()
        ->and(collect($data['daily'])->sum('words'))->toBe(0);
});

it('counts words written per day from saved versions', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create();

    writeChapter($section, 'one two three four five');
    writeChapter($section, 'one two three four five six seven eight');

    $today = collect(analyticsFor($user, $book)['daily'])->firstWhere('today', true);

    expect($today['words'])->toBe(8);
});

it('shows a day of cutting as negative', function () {
    // An author who cut four thousand words did not write four thousand.
    // Clamping to zero would hide the one thing a writing chart should be
    // honest about.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create();

    writeChapter($section, 'one two three four five six seven eight nine ten');
    writeChapter($section, 'one two three');

    // Move EVERY snapshot so far into the past. The writer snapshots the
    // state before a write, so the second save also created a version dated
    // today holding the old text — leaving it there makes the day read as a
    // gain followed by a cut, which is not what is being tested.
    ManuscriptVersion::where('manuscript_section_id', $section->id)
        ->update(['created_at' => now()->subDays(2)]);

    $daily = collect(analyticsFor($user, $book)['daily']);

    expect($daily->firstWhere('today', true)['words'])->toBeLessThan(0);
});

it('measures the first day against what came before the window', function () {
    // Without a baseline the first day in range reads as though the whole
    // chapter was written that morning.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create();

    writeChapter($section, 'one two three four five six seven eight nine ten');
    writeChapter($section, 'one two three four five six seven eight nine ten eleven');

    // Everything recorded so far belongs before the window; only the live
    // section's current size should land on today.
    ManuscriptVersion::where('manuscript_section_id', $section->id)
        ->update(['created_at' => now()->subDays(60)]);

    $data = analyticsFor($user, $book);
    $today = collect($data['daily'])->firstWhere('today', true);

    // One word added today, not eleven.
    expect($today['words'])->toBe(1);
});

it('breaks words down by chapter', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $book->sections()->delete();

    $first = $book->sections()->create([
        'type' => 'chapter', 'title' => 'One', 'position' => 100,
    ]);
    $second = $book->sections()->create([
        'type' => 'chapter', 'title' => 'Two', 'position' => 200,
    ]);

    writeChapter($first, 'one two three');
    writeChapter($second, 'one two three four five');

    $sections = collect(analyticsFor($user, $book)['by_section']);

    expect($sections->firstWhere('title', 'One')['words'])->toBe(3)
        ->and($sections->firstWhere('title', 'Two')['words'])->toBe(5);
});

it('reports every agent, including those with nothing open', function () {
    // A chart that omits the quiet agents suggests they were never run.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    expect(analyticsFor($user, $book)['by_agent'])->toHaveCount(7);
});

it('keeps the window within sane bounds', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $tooFew = $this->actingAs($user)
        ->getJson("/books/{$book->id}/analytics?days=1")
        ->json('days');

    $tooMany = $this->actingAs($user)
        ->getJson("/books/{$book->id}/analytics?days=9999")
        ->json('days');

    expect($tooFew)->toBe(7)->and($tooMany)->toBe(90);
});

it('refuses another author\'s analytics', function () {
    $book = Book::factory()->for(User::factory())->create();

    $this->actingAs(User::factory()->create())
        ->getJson("/books/{$book->id}/analytics")
        ->assertForbidden();
});
