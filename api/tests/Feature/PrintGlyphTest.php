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
