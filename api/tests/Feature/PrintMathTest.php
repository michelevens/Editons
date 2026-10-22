<?php

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\DocumentRenderer;
use App\Publishing\PrintBook;

/**
 * Formulas as they print: every letter drawn, nothing squeezed, and inline
 * ones sitting on the line rather than over its neighbours.
 */
function printMathSection(array $content, array $book = []): ManuscriptSection
{
    return ManuscriptSection::factory()
        ->for(Book::factory()->for(User::factory())->create(['title' => 'Économie'] + $book))
        ->create(['title' => 'Chapitre 1 : Agrégats', 'type' => 'chapter', 'position' => 1,
            'content' => ['type' => 'doc', 'content' => $content]]);
}

/** The SVG inside each print equation of some HTML. @return array<int, string> */
function printedSvgs(string $html): array
{
    preg_match_all('#<img class="math"[^>]*src="data:image/svg\+xml;base64,([^"]+)"#', $html, $m);

    return array_map('base64_decode', $m[1]);
}

it('draws accented letters in formulas instead of dropping them', function () {
    // MathJax hands over é and ô as SVG text, which the PDF set in a
    // Times without them: "PIB réel" printed as "PIB r el".
    $math = fn (string $latex) => printedSvgs(app(DocumentRenderer::class)->withMath('svg')->html(printMathSection([
        ['type' => 'paragraph', 'content' => [['type' => 'inlineMath', 'attrs' => ['latex' => $latex]]]],
    ])))[0];

    $accented = $math('\text{PIB réel} = \text{impôts}');
    $plain = $math('\text{PIB rel} = \text{imp}');

    expect($accented)->not->toContain('<text')
        ->and(substr_count($accented, '<path'))->toBeGreaterThan(substr_count($plain, '<path') + 2);
});

it('scales a formula wider than the measure in both directions', function () {
    $long = 'P(x) = (5x+3)(5x-3) - (5x+3)(x-2) = (5x+3)\left[(5x-3)-(x-2)\right] = (5x+3)(4x-1) = 20x^2 + 7x - 3';
    $section = printMathSection([['type' => 'blockMath', 'attrs' => ['latex' => $long]]]);
    $size = function (string $html) {
        preg_match('#<img class="math"[^>]*style="width:([\d.]+)pt;height:([\d.]+)pt#', $html, $m);

        return [(float) $m[1], (float) $m[2]];
    };

    $renderer = app(DocumentRenderer::class)->withMath('svg');
    [$width, $height] = $size($renderer->html($section));
    [$fitted, $fittedHeight] = $size($renderer->withMeasure(316.8, 11)->html($section));

    // 4.4in of text, less the two 3em columns either side of the equation.
    expect($width)->toBeGreaterThan(316.8 - 66)
        ->and($fitted)->toBeLessThanOrEqual(316.8 - 66 + 0.01)
        ->and($fitted / $fittedHeight)->toEqualWithDelta($width / $height, 0.05);
});

it('keeps punctuation after a formula on its line', function () {
    $html = app(DocumentRenderer::class)->html(printMathSection([['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'Donc '],
        ['type' => 'inlineMath', 'attrs' => ['latex' => '\dfrac{3}{4}']],
        ['type' => 'text', 'text' => '. Réponse : oui.'],
    ]]]));

    expect($html)->toMatch('#<span style="white-space:nowrap"><math[^>]*>.*</math>\.</span> Réponse : oui\.#s');
});

it('seats inline formulas on the baseline and opens the line for tall ones', function () {
    $print = app(PrintBook::class);
    $book = Book::factory()->for(User::factory())->make();
    $seat = fn (string $style) => (new ReflectionMethod($print, 'seatInlineMath'))
        ->invoke($print, '<img class="math" alt="x" style="'.$style.'" src="x" />', $book, 1.3);

    // A plain formula: no extra room, aligned by its own depth.
    $small = $seat('width:20pt;height:10pt;vertical-align:-2pt');
    // An inline \dfrac, taller than the line and deep below the baseline.
    $tall = $seat('width:40pt;height:30pt;vertical-align:-12pt');

    preg_match('#vertical-align:(-?[\d.]+)pt;margin:(-?[\d.]+)pt 0#', $small, $s);
    preg_match('#vertical-align:(-?[\d.]+)pt;margin:(-?[\d.]+)pt 0#', $tall, $t);

    // The small one keeps the line as it is: its box is no taller than the
    // line, so only its own depth moves it. The tall one gets a taller box,
    // and drops by the share of it that goes above the baseline.
    expect((float) $s[1])->toBe(-2.0)
        ->and((float) $t[2] + 30)->toBeGreaterThan(2 * ((float) $s[2] + 10))
        ->and((float) $t[1])->toBeLessThan(-12.0);
});

it('breaks a run too long for the measure rather than overflowing', function () {
    $section = printMathSection([['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Réponse : '.str_repeat('_', 120)]]]]);
    $book = $section->book;

    expect(app(PrintBook::class)->html($book, $book->sections()->get(), 'fr'))->toContain('body { overflow-wrap: break-word; }');
});

it('gives the PDF bookmarks and a language', function () {
    $book = Book::factory()->for(User::factory())->create(['title' => 'Économie', 'language' => 'French']);
    foreach ([['Partie I — Bases', 'part'], ['Chapitre 1 : Agrégats', 'chapter'], ['Chapitre 2 : Prix', 'chapter']] as $i => [$title, $type]) {
        ManuscriptSection::factory()->for($book)->create(['title' => $title, 'type' => $type, 'position' => $i + 1,
            'content' => ['type' => 'doc', 'content' => $type === 'part' ? [] : [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Du texte.']]]]]]);
    }

    $pdf = app(PrintBook::class)->pdf($book, $book->sections()->orderBy('position')->get(), 'fr');
    $title = fn (string $t) => '<FEFF'.strtoupper(bin2hex(mb_convert_encoding($t, 'UTF-16BE', 'UTF-8'))).'>';

    expect($pdf)->toContain('/Type /Outlines')
        ->toContain('/Lang (fr)')
        ->toContain('/PageMode /UseOutlines')
        ->toContain('/Title '.$title('Table des matières'))
        ->toContain('/Title '.$title('Chapitre 2 : Prix'))
        // An update chained to dompdf's own cross-reference table.
        ->toMatch('#/Prev \d+ >>\nstartxref\n\d+\n%%EOF\n$#');
});
