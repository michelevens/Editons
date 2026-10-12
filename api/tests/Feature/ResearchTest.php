<?php

use App\Editorial\ConsistencyIndex;
use App\Models\Book;
use App\Models\Citation;
use App\Models\ManuscriptSection;
use App\Models\ResearchSource;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\QueryException;

function bookWithChapters(array $chapters): Book
{
    $book = Book::factory()->for(User::factory())->create();
    $book->sections()->delete();

    $position = 0;

    foreach ($chapters as $title => $text) {
        $position += ManuscriptSection::POSITION_STEP;

        $book->sections()->create([
            'type' => 'chapter',
            'title' => $title,
            'position' => $position,
            'content' => ['type' => 'doc', 'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ]]],
        ]);
    }

    return $book->refresh();
}

// --- Research library -----------------------------------------------------

it('keeps a source unverified until someone vouches for it', function () {
    $source = ResearchSource::factory()->create();

    expect($source->verification)->toBe('unverified')
        ->and($source->isUsableAsEvidence())->toBeFalse();
});

it('will not treat a disputed source as evidence', function () {
    // Recording a dispute and then letting the source go on backing claims
    // would make the dispute decorative.
    $source = ResearchSource::factory()->create(['verification' => 'disputed']);

    expect($source->isUsableAsEvidence())->toBeFalse();
});

it('builds a readable short reference', function () {
    $source = ResearchSource::factory()->create([
        'authors' => 'Smith, J.',
        'published' => '2019',
        'title' => 'Practice Economics',
    ]);

    expect($source->reference())->toBe('Smith, J. (2019)');
});

it('falls back to the title when a source has no author', function () {
    $source = ResearchSource::factory()->create([
        'authors' => null, 'publisher' => null,
        'published' => '2020', 'title' => 'Annual Review',
    ]);

    expect($source->reference())->toBe('Annual Review (2020)');
});

// --- Citation provenance --------------------------------------------------

it('refuses at the database level to mark a claim supported with no source', function () {
    // This is the integrity rule the whole provenance feature rests on, so
    // it is enforced by a CHECK constraint rather than by application code
    // that a future caller could bypass.
    $section = ManuscriptSection::factory()
        ->for(Book::factory()->for(User::factory()))
        ->create();

    expect(fn () => Citation::create([
        'manuscript_section_id' => $section->id,
        'claim' => '47 percent of practices close early.',
        'status' => 'supported',
        'research_source_id' => null,
    ]))->toThrow(QueryException::class);
});

it('allows a claim to be recorded before its source is found', function () {
    $section = ManuscriptSection::factory()
        ->for(Book::factory()->for(User::factory()))
        ->create();

    $citation = Citation::create([
        'manuscript_section_id' => $section->id,
        'claim' => 'Most practices close early.',
        'status' => 'needs_source',
    ]);

    expect($citation->status)->toBe('needs_source')
        ->and($citation->source)->toBeNull();
});

it('flags a supported claim that rests on an unverified source', function () {
    // A bibliography that looks complete while resting on unchecked sources
    // is worse than one that is obviously unfinished.
    $book = Book::factory()->for(User::factory())->create();
    $section = ManuscriptSection::factory()->for($book)->create();
    $source = ResearchSource::factory()->for($book)->create(['verification' => 'unverified']);

    $citation = Citation::create([
        'manuscript_section_id' => $section->id,
        'research_source_id' => $source->id,
        'claim' => 'A claim.',
        'status' => 'supported',
    ]);

    expect($citation->restsOnUnverifiedSource())->toBeTrue();
});

it('does not flag a claim backed by a verified source', function () {
    $book = Book::factory()->for(User::factory())->create();
    $section = ManuscriptSection::factory()->for($book)->create();
    $source = ResearchSource::factory()->for($book)->create(['verification' => 'verified']);

    $citation = Citation::create([
        'manuscript_section_id' => $section->id,
        'research_source_id' => $source->id,
        'claim' => 'A claim.',
        'status' => 'supported',
    ]);

    expect($citation->restsOnUnverifiedSource())->toBeFalse();
});

it('detects a citation whose manuscript text has been deleted', function () {
    $section = ManuscriptSection::factory()
        ->for(Book::factory()->for(User::factory()))
        ->create();

    $citation = Citation::create([
        'manuscript_section_id' => $section->id,
        'claim' => 'A claim.',
        'status' => 'needs_source',
        'excerpt' => 'the original sentence',
    ]);

    expect($citation->isStale('the original sentence is here'))->toBeFalse()
        ->and($citation->isStale('this text is entirely different'))->toBeTrue();
});

// --- Cross-chapter consistency -------------------------------------------

it('finds a name spelled differently in a later chapter', function () {
    // The failure a per-chapter pass structurally cannot see.
    $book = bookWithChapters([
        'Chapter 1' => 'John arrived at the clinic early.',
        'Chapter 2' => 'Later that week Jon left for good.',
    ]);

    $book->terms()->create([
        'kind' => 'character',
        'canonical' => 'John',
        'variants' => ['Jon'],
    ]);

    $issues = app(ConsistencyIndex::class)->scan($book);

    expect($issues)->toHaveCount(1)
        ->and($issues[0]['canonical'])->toBe('John')
        ->and($issues[0]['variant'])->toBe('Jon')
        ->and($issues[0]['sections'][0]['section_title'])->toBe('Chapter 2');
});

it('does not match a variant inside a longer word', function () {
    // "Jon" must not fire on "Jonathan".
    $book = bookWithChapters(['Chapter 1' => 'Jonathan runs the practice.']);

    $book->terms()->create([
        'canonical' => 'John',
        'variants' => ['Jon'],
    ]);

    expect(app(ConsistencyIndex::class)->scan($book))->toBe([]);
});

it('treats capitalisation as a real inconsistency', function () {
    // Folding case would hide exactly what the author asked to be told about.
    $book = bookWithChapters(['Chapter 1' => 'He joined the bureau last year.']);

    $book->terms()->create([
        'canonical' => 'the Bureau',
        'variants' => ['bureau'],
    ]);

    expect(app(ConsistencyIndex::class)->scan($book))->toHaveCount(1);
});

it('counts every occurrence across the book', function () {
    $book = bookWithChapters([
        'Chapter 1' => 'Jon spoke. Jon left.',
        'Chapter 2' => 'Jon returned.',
    ]);

    $book->terms()->create([
        'canonical' => 'John',
        'variants' => ['Jon'],
    ]);

    $issues = app(ConsistencyIndex::class)->scan($book);

    expect($issues[0]['total'])->toBe(3)
        ->and($issues[0]['sections'])->toHaveCount(2);
});

it('reports nothing when the book has no declared vocabulary', function () {
    // Guessing canonical spellings from frequency is the inference this
    // class exists to avoid.
    $book = bookWithChapters(['Chapter 1' => 'Jon and John and Jonathan.']);

    expect(app(ConsistencyIndex::class)->scan($book))->toBe([]);
});

it('says nothing when the canonical spelling is used throughout', function () {
    $book = bookWithChapters([
        'Chapter 1' => 'John arrived.',
        'Chapter 2' => 'John left.',
    ]);

    $book->terms()->create([
        'canonical' => 'John',
        'variants' => ['Jon'],
    ]);

    expect(app(ConsistencyIndex::class)->scan($book))->toBe([]);
});

it('scopes the library and vocabulary to one book', function () {
    $mine = Book::factory()->for(User::factory())->create();
    $theirs = Book::factory()->for(User::factory())->create();

    ResearchSource::factory()->for($theirs)->create();
    $theirs->terms()->create(['canonical' => 'X', 'variants' => ['x']]);

    expect($mine->sources()->count())->toBe(0)
        ->and($mine->terms()->count())->toBe(0);
});
