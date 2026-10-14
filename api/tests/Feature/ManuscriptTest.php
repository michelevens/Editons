<?php

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\ManuscriptVersion;
use App\Models\User;
use App\Services\ManuscriptWriter;

/** A minimal ProseMirror document, as Tiptap would emit. */
function doc(string $text): array
{
    return ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
    ]];
}

it('creates a book with an opening chapter', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/books', ['title' => 'The Handbook'])
        ->assertRedirect();

    $book = Book::firstWhere('title', 'The Handbook');

    expect($book->user_id)->toBe($user->id)
        // A book with an empty binder gives the editor nowhere to type.
        ->and($book->sections)->toHaveCount(1)
        ->and($book->sections->first()->title)->toBe('Chapter 1');
});

it('refuses to let a stranger open someone else owns book', function () {
    $book = Book::factory()->for(User::factory())->create();

    $this->actingAs(User::factory()->create())
        ->get("/books/{$book->id}/edit")
        ->assertForbidden();
});

it('never takes ownership from request input', function () {
    $owner = User::factory()->create();
    $attacker = User::factory()->create();

    // user_id is not fillable; passing it must be ignored, not honoured.
    $this->actingAs($attacker)->post('/books', [
        'title' => 'Hijack',
        'user_id' => $owner->id,
    ]);

    expect(Book::firstWhere('title', 'Hijack')->user_id)->toBe($attacker->id);
});

it('counts words from the ProseMirror tree, not from markup', function () {
    expect(ManuscriptSection::countWords(doc('one two three')))->toBe(3)
        ->and(ManuscriptSection::countWords(doc('  spaced   out  ')))->toBe(2)
        ->and(ManuscriptSection::countWords(null))->toBe(0)
        // Accents must not split a word.
        ->and(ManuscriptSection::countWords(doc('éducation française')))->toBe(2);
});

it('saves content and rolls the word count up to the book', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create();

    app(ManuscriptWriter::class)->save($section, doc('four little words here'), userId: $user->id);

    expect($section->fresh()->word_count)->toBe(4)
        ->and($book->fresh()->word_count)->toBe(4);
});

it('snapshots the state before a write, so a version is somewhere to return to', function () {
    $user = User::factory()->create();
    $section = ManuscriptSection::factory()
        ->for(Book::factory()->for($user))
        ->create(['content' => doc('original text')]);

    app(ManuscriptWriter::class)->save($section, doc('replacement'), reason: 'manual', userId: $user->id);

    $version = $section->versions()->first();

    // The snapshot holds what was there BEFORE, not after.
    expect($version->version_number)->toBe(1)
        ->and($version->content)->toEqual(doc('original text'))
        ->and($section->fresh()->content)->toEqual(doc('replacement'));
});

it('throttles autosave snapshots so history is not a keystroke log', function () {
    $user = User::factory()->create();
    $section = ManuscriptSection::factory()
        ->for(Book::factory()->for($user))
        ->create(['content' => doc('start')]);

    $writer = app(ManuscriptWriter::class);

    // Three autosaves in quick succession.
    $writer->save($section, doc('one'), userId: $user->id);
    $writer->save($section->fresh(), doc('two'), userId: $user->id);
    $writer->save($section->fresh(), doc('three'), userId: $user->id);

    expect($section->versions()->count())->toBe(1);

    // An explicit save always earns one, regardless of the interval.
    $writer->save($section->fresh(), doc('four'), reason: 'manual', userId: $user->id);

    expect($section->versions()->count())->toBe(2);
});

it('does not snapshot an unchanged save', function () {
    $user = User::factory()->create();
    $section = ManuscriptSection::factory()
        ->for(Book::factory()->for($user))
        ->create(['content' => doc('unchanged')]);

    app(ManuscriptWriter::class)->save($section, doc('unchanged'), reason: 'manual', userId: $user->id);

    expect($section->versions()->count())->toBe(0);
});

it('restores an earlier version without losing the one being replaced', function () {
    $user = User::factory()->create();
    $section = ManuscriptSection::factory()
        ->for(Book::factory()->for($user))
        ->create(['content' => doc('version one')]);

    $writer = app(ManuscriptWriter::class);
    $writer->save($section, doc('version two'), reason: 'manual', userId: $user->id);

    $v1 = $section->versions()->where('version_number', 1)->first();
    $writer->restore($section->fresh(), $v1, $user->id);

    expect($section->fresh()->content)->toEqual(doc('version one'))
        // Going back is itself recorded, so 'version two' is still reachable.
        ->and($section->versions()->count())->toBe(2)
        ->and($section->versions()->first()->content)->toEqual(doc('version two'));
});

it('treats versions as immutable', function () {
    $section = ManuscriptSection::factory()->for(Book::factory()->for(User::factory()))->create();
    $version = ManuscriptVersion::create([
        'manuscript_section_id' => $section->id,
        'version_number' => 1,
        'title' => 'v1',
        'content' => doc('frozen'),
        'word_count' => 1,
        'reason' => 'manual',
    ]);

    expect(fn () => $version->update(['title' => 'tampered']))->toThrow(LogicException::class);
    expect(fn () => $version->delete())->toThrow(LogicException::class);
});

it('autosaves over HTTP and reports the new counts', function () {
    $user = User::factory()->create();
    $section = ManuscriptSection::factory()->for(Book::factory()->for($user))->create();

    $this->actingAs($user)
        ->putJson("/sections/{$section->id}/content", ['content' => doc('five words go in here')])
        ->assertOk()
        ->assertJsonStructure(['saved_at', 'word_count', 'book_word_count', 'versions_count'])
        ->assertJsonPath('word_count', 5);
});

it('refuses to save into a section of a book you do not own', function () {
    $section = ManuscriptSection::factory()->for(Book::factory()->for(User::factory()))->create();

    $this->actingAs(User::factory()->create())
        ->putJson("/sections/{$section->id}/content", ['content' => doc('intrusion')])
        ->assertForbidden();
});

it('refuses to restore a version belonging to a different section', function () {
    $user = User::factory()->create();
    $mine = ManuscriptSection::factory()->for(Book::factory()->for($user))->create();
    $theirs = ManuscriptSection::factory()->for(Book::factory()->for($user))->create();

    $foreign = ManuscriptVersion::create([
        'manuscript_section_id' => $theirs->id,
        'version_number' => 1,
        'title' => 'theirs',
        'content' => doc('other chapter'),
        'word_count' => 2,
        'reason' => 'manual',
    ]);

    $this->actingAs($user)
        ->postJson("/sections/{$mine->id}/versions/{$foreign->id}/restore")
        ->assertNotFound();
});

it('rejects a parent section from another book', function () {
    $user = User::factory()->create();
    $bookA = Book::factory()->for($user)->create();
    $bookB = Book::factory()->for($user)->create();
    $foreignParent = ManuscriptSection::factory()->for($bookB)->create(['type' => 'part']);

    $this->actingAs($user)
        ->post("/books/{$bookA->id}/sections", [
            'type' => 'chapter',
            'title' => 'Grafted',
            'parent_id' => $foreignParent->id,
        ])
        ->assertNotFound();
});

it('accepts the unload save a beacon sends over POST', function () {
    // navigator.sendBeacon can only issue POST, and it is what carries a
    // writer's last edit when they close the tab. Against the PUT-only route
    // this returned 405 and the edit was lost — silently, because a beacon
    // has no response to inspect. This is the one save path where a failure
    // is unrecoverable, so it is tested directly.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create();

    $this->actingAs($user)
        ->postJson("/sections/{$section->id}/content", [
            'content' => ['type' => 'doc', 'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => 'The last thing they typed.']],
            ]]],
        ])
        ->assertOk();

    expect($section->fresh()->plainText())->toBe('The last thing they typed.');
});

it('still refuses a beacon save from somebody who cannot edit the chapter', function () {
    // A second verb on the same action must not be a second authorisation
    // path. It routes to the same controller method, and this proves it.
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    $section = ManuscriptSection::factory()
        ->for(Book::factory()->for($owner))
        ->create();

    $this->actingAs($stranger)
        ->postJson("/sections/{$section->id}/content", [
            'content' => ['type' => 'doc', 'content' => []],
        ])
        ->assertForbidden();
});

it('accepts a beacon body carrying the token, with no CSRF header', function () {
    // Exactly what navigator.sendBeacon sends: a JSON body with `_token` in
    // it and no headers at all. The CSRF middleware is left switched on,
    // because this endpoint rewrites a manuscript and exempting it to make a
    // beacon work would be the wrong trade.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create();

    $this->withMiddleware()
        ->actingAs($user)
        ->call(
            'POST',
            "/sections/{$section->id}/content",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'content' => doc('Saved as the tab closed.'),
                '_token' => csrf_token(),
            ]),
        )
        ->assertOk();

    expect($section->fresh()->plainText())->toBe('Saved as the tab closed.');
});
