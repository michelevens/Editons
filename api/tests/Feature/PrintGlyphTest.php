<?php

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\PrintBook;

/**
 * The PDF's text face lacks some characters; dompdf prints those as empty
 * boxes unless they are set in a face that has them.
 */
it('sets characters the book face lacks in a fallback face', function () {
    $book = Book::factory()->for(User::factory())->create(['title' => 'Réussir']);
    ManuscriptSection::factory()->for($book)->create(['title' => 'Chapitre 1 : Bases', 'type' => 'chapter', 'position' => 10,
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Exercice 1.1 (★★☆, 6 min) : la note fa♯ et l’ensemble ℝ, café.']],
        ]]]]);

    $html = app(PrintBook::class)->html($book, $book->sections()->get(), 'fr');

    expect($html)->toContain('<span class="glyph">★★☆</span>')
        ->toContain('fa<span class="glyph">♯</span>')
        ->toContain('<span class="glyph">ℝ</span>')
        // Accented letters, typographic quotes and the hyphenator's soft
        // hyphens are in the face: left alone.
        ->toContain("l’en\u{00AD}semble")
        ->toContain('café.')
        ->not->toContain('<span class="glyph">é');
});

it('checks the character map of the face actually drawing', function () {
    // Source Sans 3 lacks Ƃ, which Garamond has; DejaVu Sans Mono has ←,
    // which code used to print as "?" in the PDF's built-in Courier.
    $book = Book::factory()->for(User::factory())->create(['title' => 'Algo', 'layout' => ['font' => 'sans']]);
    ManuscriptSection::factory()->for($book)->create(['title' => 'Chapitre 1 : Bases', 'type' => 'chapter', 'position' => 10,
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Lettre Ƃ, puis '],
                ['type' => 'text', 'text' => 'Pour i ← 1 à N ★', 'marks' => [['type' => 'code']]],
            ],
        ]]]]);

    $html = app(PrintBook::class)->html($book, $book->sections()->get(), 'fr');

    expect($html)->toContain('Lettre <span class="glyph">Ƃ</span>')
        ->toContain('<code>Pour i ← 1 à N ★</code>')
        ->toContain("code, kbd, samp { font-family: 'DejaVu Sans Mono'");
});

it('embeds every font the PDF uses', function () {
    // A print-on-demand preflight rejects a PDF that leans on the reader's
    // own Courier or Times, and those have no "←" anyway.
    $book = Book::factory()->for(User::factory())->create(['title' => 'Algo']);
    ManuscriptSection::factory()->for($book)->create(['title' => 'Chapitre 1', 'type' => 'chapter', 'position' => 10,
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Affectation : '],
                ['type' => 'text', 'text' => 'x ← x * 2', 'marks' => [['type' => 'code']]],
            ],
        ]]]]);

    $pdf = app(PrintBook::class)->pdf($book, $book->sections()->get(), 'fr');

    expect($pdf)->toContain('DejaVuSansMono')
        ->and(preg_match('#/BaseFont\s*/(Courier|Times|Helvetica|Symbol)#', $pdf))->toBe(0);
});
