<?php

use App\Covers\CoverFonts;
use App\Covers\CoverLayers;
use App\Covers\CoverText;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The live canvas and the server break lines identically.
 *
 * Cover Studio draws layers in the browser with coverText.js and the server
 * with CoverText. This runs the JavaScript under node on the same layers,
 * texts and font metrics and requires the same lines, baselines and glyph
 * positions — the guarantee that a box the author arranges on screen is the
 * box in the JPEG.
 */
it('wraps and places text exactly as the browser does', function () {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        $this->markTestSkipped('node is not installed.');
    }

    $cases = [];
    $long = 'Réussir l\'examen du Bac — Kreyòl Ayisyen, fransè ak matematik pou tout elèv yo';

    foreach (['eb-garamond', 'playfair-display', 'cormorant-garamond', 'montserrat', 'oswald', 'bebas-neue', 'great-vibes', 'cinzel'] as $i => $font) {
        foreach ([0.25, 0.5, 0.9] as $width) {
            $cases[] = [
                'layer' => CoverLayers::normalise(CoverLayers::make('custom', [
                    'text' => $long, 'font' => $font, 'x' => 1 - $width, 'width' => $width,
                    'size' => 0.02 + 0.01 * $i, 'align' => ['left', 'center', 'right'][$i % 3],
                    'tracking' => [0, 0.08, -0.02][$i % 3], 'leading' => 1.1 + 0.1 * ($i % 3),
                    'uppercase' => $i % 2 === 1,
                ])),
                'text' => $long,
                'width' => 1600,
                'height' => 2560,
            ];
        }
    }

    // Edges: explicit line breaks, blank lines, a word wider than its box,
    // a character no face has, nothing at all, and a small canvas.
    $edge = fn (string $text, array $layer = []) => [
        'layer' => CoverLayers::normalise(CoverLayers::make('custom', array_merge(['text' => $text], $layer))),
        'text' => $text,
        'width' => 1600,
        'height' => 2560,
    ];
    $cases[] = $edge("Line one\n\nLine  three   spaced");
    $cases[] = $edge('Supercalifragilisticexpialidocious', ['width' => 0.1, 'size' => 0.05]);
    $cases[] = $edge('Note ★ ♭ ∑', ['font' => 'lora']);
    $cases[] = $edge('');
    $cases[] = array_merge($edge('Small canvas, same breaks', ['width' => 0.3]), ['width' => 100, 'height' => 160]);
    $cases[] = $edge("e\u{0301}cole straße", ['uppercase' => true]);

    $faces = array_values(array_unique(array_merge(['fallback'], array_map(
        fn ($c) => CoverFonts::faceKey($c['layer']['font'], $c['layer']['weight'], $c['layer']['italic']),
        $cases,
    ))));
    $metrics = [];

    foreach ($faces as $face) {
        $metrics[$face] = CoverFonts::metrics($face);
    }

    $process = new Process([$node, base_path('tests/js/coverParity.mjs')]);
    $process->setInput(json_encode(['cases' => $cases, 'metrics' => $metrics]));
    $process->mustRun();

    $browser = json_decode($process->getOutput(), true);

    expect($browser)->toHaveCount(count($cases));

    foreach ($cases as $i => $case) {
        $server = CoverText::layout($case['layer'], $case['text'], $case['width'], $case['height']);
        $js = $browser[$i];

        expect(array_column($js['lines'], 'text'))->toBe(array_column($server['lines'], 'text'), "case {$i}: line breaks differ");
        expect($js['box']['height'])->toEqualWithDelta($server['box']['height'], 1e-6);

        foreach ($server['lines'] as $l => $line) {
            expect($js['lines'][$l]['baseline'])->toEqualWithDelta($line['baseline'], 1e-6)
                ->and($js['lines'][$l]['width'])->toEqualWithDelta($line['width'], 1e-6);

            foreach ($line['glyphs'] as $g => $glyph) {
                expect($js['lines'][$l]['glyphs'][$g]['x'])->toEqualWithDelta($glyph['x'], 1e-6)
                    ->and($js['lines'][$l]['glyphs'][$g]['face'])->toBe($glyph['face']);
            }
        }
    }
});
