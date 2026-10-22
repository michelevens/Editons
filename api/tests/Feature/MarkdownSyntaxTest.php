<?php

use App\Import\ImportReport;
use App\Import\MarkdownExporter;
use App\Import\MarkdownImporter;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\DocumentRenderer;

/**
 * Markdown that used to print literally in every export.
 *
 * Each case comes from the exam-prep books, where the audit found it in the
 * PDFs: "---" paragraphs, stray "**" and "*", a "\" ending a paragraph,
 * "\_\_\_" blanks and "$… = $" formulas left as code.
 */
function syntaxDoc(string $markdown): array
{
    $importer = app(MarkdownImporter::class);

    return (new ReflectionMethod($importer, 'toProseMirror'))->invoke($importer, $markdown, new ImportReport);
}

/** The first block's inline nodes, which is what most of these are about. */
function syntaxInline(string $markdown): array
{
    return syntaxDoc($markdown)['content'][0]['content'];
}

/** Every text node, flattened, to look for leftover syntax. */
function syntaxText(array $node): string
{
    $text = $node['text'] ?? '';

    foreach ($node['content'] ?? [] as $child) {
        $text .= syntaxText($child);
    }

    return $text;
}

it('turns a line of dashes into a thematic break', function () {
    $doc = syntaxDoc("Scene one.\n\n---\n\nScene two.\n\n* * *\n\nScene three.");

    expect(array_column($doc['content'], 'type'))
        ->toBe(['paragraph', 'horizontalRule', 'paragraph', 'horizontalRule', 'paragraph']);
});

it('breaks a paragraph at a rule written directly under it', function () {
    $doc = syntaxDoc("Partie I\n---\nPartie II");

    expect(array_column($doc['content'], 'type'))->toBe(['paragraph', 'horizontalRule', 'paragraph'])
        ->and(syntaxText($doc))->not->toContain('---');
});

it('keeps a line of underscores as the blank it is', function () {
    // In an exercise, underscores alone on a line are the answer space.
    $doc = syntaxDoc("1. Who do people travel to visit?\n\n_____");

    expect(array_column($doc['content'], 'type'))->toBe(['orderedList', 'paragraph'])
        ->and($doc['content'][1]['content'][0]['text'])->toBe('_____');
});

it('reads nested emphasis the way CommonMark does', function () {
    // From the 9e français key: italic sentence, bold-italic last word.
    $nodes = syntaxInline('*Les seaux que Wilson a **lavés*** (2 pts).');

    expect($nodes)->toBe([
        ['type' => 'text', 'text' => 'Les seaux que Wilson a ', 'marks' => [['type' => 'italic']]],
        ['type' => 'text', 'text' => 'lavés', 'marks' => [['type' => 'bold'], ['type' => 'italic']]],
        ['type' => 'text', 'text' => ' (2 pts).'],
    ]);
});

it('closes bold around italic and punctuation', function () {
    // "***lejos*.**": bold of (italic "lejos" + ".").
    $nodes = syntaxInline('— ***lejos*.** Cerca');

    expect($nodes)->toBe([
        ['type' => 'text', 'text' => '— '],
        ['type' => 'text', 'text' => 'lejos', 'marks' => [['type' => 'bold'], ['type' => 'italic']]],
        ['type' => 'text', 'text' => '.', 'marks' => [['type' => 'bold']]],
        ['type' => 'text', 'text' => ' Cerca'],
    ]);
});

it('bolds a word ending in an apostrophe or a Spanish opening mark', function () {
    expect(syntaxInline("« **N'**as-tu pas faim ? »")[1])
        ->toBe(['type' => 'text', 'text' => "N'", 'marks' => [['type' => 'bold']]])
        ->and(syntaxInline("une liste d'**« Éléments attendus »**.")[1])
        ->toBe(['type' => 'text', 'text' => '« Éléments attendus »', 'marks' => [['type' => 'bold']]])
        ->and(syntaxText(['content' => syntaxInline('*Daniela, **¿**tienes hambre?*')]))
        ->toBe('Daniela, ¿tienes hambre?');
});

it('leaves an asterisk that pairs with nothing as text', function () {
    // C* (asymmetric carbon) and x * 2 are not emphasis.
    expect(syntaxText(['content' => syntaxInline('noté C*, et x ← x * 2')]))
        ->toBe('noté C*, et x ← x * 2');
});

it('drops a backslash that ends a paragraph and keeps the inner breaks', function () {
    $nodes = syntaxInline("a. Ce médicament va ______ la douleur.\\\nb. Sur le podium.\\");

    expect(array_column($nodes, 'type'))->toBe(['text', 'hardBreak', 'text'])
        ->and(end($nodes)['text'])->toBe('b. Sur le podium.');
});

it('reads backslash escapes as the character', function () {
    expect(syntaxText(['content' => syntaxInline('Complète : \_\_\_ et \*pas en italique\*, 5 \$.')]))
        ->toBe('Complète : ___ et *pas en italique*, 5 $.');
});

it('takes a formula whose closing dollar follows a space', function () {
    // "$AN = 0{,}75 \times 8 = $ **6 cm**" printed as raw LaTeX.
    $nodes = syntaxInline('Thalès : $AN = 0{,}75 \times 8 = $ **6 cm** ; $\frac{72}{2} = $ 36°.');

    expect($nodes[1])->toBe(['type' => 'inlineMath', 'attrs' => ['latex' => 'AN = 0{,}75 \times 8 =']])
        ->and($nodes[3])->toBe(['type' => 'text', 'text' => '6 cm', 'marks' => [['type' => 'bold']]])
        ->and($nodes[5])->toBe(['type' => 'inlineMath', 'attrs' => ['latex' => '\frac{72}{2} =']])
        ->and(syntaxText(['content' => $nodes]))->not->toContain('$');
});

it('still reads prices as prices', function () {
    expect(syntaxText(['content' => syntaxInline('Mozambique (80 $) < Somalie (120 $) ; $25 and $30.')]))
        ->toBe('Mozambique (80 $) < Somalie (120 $) ; $25 and $30.');
});

it('does not cut a table cell at a bar inside a formula or code', function () {
    $doc = syntaxDoc("| Loi | Formule |\n| --- | --- |\n| Induction | \$|\\Delta\\Phi| = 2NBS\$ |\n| OU | `a \\|\\| b` |");
    $rows = $doc['content'][0]['content'];

    expect(count($rows[1]['content']))->toBe(2)
        ->and($rows[1]['content'][1]['content'][0]['content'][0])
        ->toBe(['type' => 'inlineMath', 'attrs' => ['latex' => '|\Delta\Phi| = 2NBS']])
        ->and($rows[2]['content'][1]['content'][0]['content'][0]['text'])->toBe('a || b');
});

it('keeps an ordered list starting number and its continuation lines', function () {
    // Question 15 of an exam paper, with its a. b. c. lines under it.
    $doc = syntaxDoc("15. Complète les phrases :\\\na. Ce médicament va ______ la douleur.\\\nb. Sur le podium.\n16. Remplace le mot.");
    $list = $doc['content'][0];

    expect($list['type'])->toBe('orderedList')
        ->and($list['attrs'])->toBe(['start' => 15])
        ->and(count($list['content']))->toBe(2)
        ->and(array_column($list['content'][0]['content'][0]['content'], 'type'))
        ->toBe(['text', 'hardBreak', 'text', 'hardBreak', 'text']);
});

it('nests an indented list inside its item', function () {
    $doc = syntaxDoc("- Préparer le matériel :\n  - deux stylos ;\n  - une règle.\n- Dormir.");
    $items = $doc['content'][0]['content'];

    expect(count($items))->toBe(2)
        ->and($items[0]['content'][1]['type'])->toBe('bulletList')
        ->and(count($items[0]['content'][1]['content']))->toBe(2);
});

it('starts a list written directly under a line of text', function () {
    $doc = syntaxDoc("Caractéristiques suivantes :\n- Espérance de vie : 64 ans\n- Taux d'alphabétisation : 61 %");

    expect(array_column($doc['content'], 'type'))->toBe(['paragraph', 'bulletList']);
});

it('reads strikethrough and underline', function () {
    $nodes = syntaxInline('Piège : ~~nos sientimos~~ ; <u>Maria</u> (_____) is studying.');

    expect($nodes[1])->toBe(['type' => 'text', 'text' => 'nos sientimos', 'marks' => [['type' => 'strike']]])
        ->and($nodes[3])->toBe(['type' => 'text', 'text' => 'Maria', 'marks' => [['type' => 'underline']]]);
});

it('keeps a heading to its own line', function () {
    $doc = syntaxDoc("### Exercice 1\nTexte sous le titre.");

    expect(array_column($doc['content'], 'type'))->toBe(['heading', 'paragraph']);
});

it('round-trips the new constructs through the Markdown export', function () {
    $markdown = "# Chapitre\n\nUn.\n\n---\n\n15. Question :\\\na. ligne\n16. Suite\n\n- Liste\n  - imbriquée\n\n~~faux~~ et <u>souligné</u>";

    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $book->sections()->delete();
    app(MarkdownImporter::class)->import($book, $markdown, $user->id);

    expect(trim(app(MarkdownExporter::class)->book($book)))->toBe($markdown);
});

it('prints an ordered list from its first number', function () {
    $section = ManuscriptSection::factory()->for(Book::factory()->for(User::factory()))->create([
        'content' => syntaxDoc("15. Question quinze\n16. Question seize"),
    ]);

    expect((new DocumentRenderer)->html($section))->toContain('<ol start="15">')
        ->and((new DocumentRenderer)->markdown($section))->toContain('16. Question seize');
});
