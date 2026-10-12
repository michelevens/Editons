<?php

use App\Import\MarkdownExporter;
use App\Import\MarkdownImporter;
use App\Models\Book;
use App\Models\User;

function importBook(string $markdown): array
{
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $book->sections()->delete(); // factory chapter would offset positions

    $report = app(MarkdownImporter::class)->import($book, $markdown, $user->id);

    return [$book->refresh(), $report];
}

it('splits a manuscript into one section per heading', function () {
    [$book, $report] = importBook("# One\n\nBody one.\n\n# Two\n\nBody two.");

    expect($report->sections)->toBe(2)
        ->and($book->sections()->pluck('title')->all())->toBe(['One', 'Two']);
});

it('keeps text that appears before the first heading', function () {
    // Silently dropping this is the most likely way an importer loses a
    // manuscript's opening page.
    [$book] = importBook("An untitled preface.\n\n# One\n\nBody.");

    expect($book->sections()->orderBy('position')->first()->title)->toBe('Untitled')
        ->and($book->sections()->orderBy('position')->first()->plainText())
        ->toContain('An untitled preface.');
});

it('round-trips a manuscript without losing content', function () {
    // Milestone 1's actual acceptance criterion, as a check rather than a
    // claim: import it, export it, compare byte for byte.
    $markdown = <<<'MD'
    # Chapter One

    This is a paragraph with **bold text**, *italic text* and `inline code`.

    A second paragraph follows.

    > A quoted line from somewhere.

    - First bullet
    - Second bullet

    1. Ordered one
    2. Ordered two

    # Chapter Two

    Short chapter body.
    MD;

    [$book] = importBook($markdown);

    $exported = app(MarkdownExporter::class)->book($book);

    expect(trim($exported))->toBe(trim($markdown));
});

it('is stable when round-tripped twice', function () {
    // A converter can be lossless once and still oscillate between two
    // spellings forever. The second pass is what proves it settles.
    $markdown = "# One\n\nText with **bold** and *italic*.\n\n- a\n- b";

    [$book] = importBook($markdown);
    $once = app(MarkdownExporter::class)->book($book);

    [$again] = importBook($once);
    $twice = app(MarkdownExporter::class)->book($again);

    expect(trim($twice))->toBe(trim($once));
});

it('reports what it could not carry instead of dropping it silently', function () {
    [, $report] = importBook(
        "# One\n\nText.\n\n```php\n\$x = 1;\n```\n\n![a picture](cat.png)"
    );

    expect($report->skipped)->toBe(['code block' => 1, 'image' => 1])
        ->and($report->toArray()['lossless'])->toBeFalse();
});

it('reports a clean import as lossless', function () {
    [, $report] = importBook("# One\n\nJust prose.");

    expect($report->toArray()['lossless'])->toBeTrue();
});

it('appends to an existing binder rather than replacing it', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $existing = $book->sections()->count();

    app(MarkdownImporter::class)->import($book, "# Imported\n\nBody.", $user->id);

    expect($book->refresh()->sections()->count())->toBe($existing + 1)
        ->and($book->sections()->orderBy('position')->get()->last()->title)
        ->toBe('Imported');
});

it('leaves imported content revertible once it is edited', function () {
    // The import itself cuts no version: the section it writes into was
    // created empty moments earlier, and ManuscriptWriter deliberately does
    // not snapshot empty prior state. What matters is that the imported text
    // becomes the state a later edit can be rolled back TO.
    [$book] = importBook("# One\n\nOriginal imported body.");

    $section = $book->sections()->first();
    expect($section->versions()->count())->toBe(0);

    app(App\Services\ManuscriptWriter::class)->save(
        section: $section,
        content: ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Edited afterwards.']],
        ]]],
        reason: 'manual',
    );

    $version = $section->versions()->latest('version_number')->first();

    expect($version)->not->toBeNull()
        ->and(json_encode($version->content))->toContain('Original imported body.');
});

it('counts imported words toward the book total', function () {
    [$book] = importBook("# One\n\nOne two three four five.");

    expect($book->word_count)->toBe(5);
});

it('does not produce empty text nodes that break the editor', function () {
    // ProseMirror rejects a text node with an empty string; one of these in
    // an imported document makes the whole chapter fail to load.
    [$book] = importBook("# One\n\nText.\n\n\n\nMore text.");

    $json = json_encode($book->sections()->first()->content);

    expect($json)->not->toContain('"text":""');
});
