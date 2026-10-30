<?php

use App\Editorial\Style\Typography;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\Exporter;
use App\Publishing\PrintBook;
use Illuminate\Support\Facades\Storage;

/**
 * Per-book language and typography (ADR — Book language and typography):
 * the rules, their settings, the "Fix typography" pass, the export option,
 * and that a book nobody touches is stored and exported as before.
 */
const FR = ['quotes' => 'guillemets', 'french_spacing' => true, 'dashes' => true, 'ellipsis' => true];
const EN = ['quotes' => 'curly', 'french_spacing' => false, 'dashes' => true, 'ellipsis' => true];

function typoBook(array $attributes = [], array $paragraphs = ['Il a dit "bonjour" ! Quoi? Note: voir https://ecrivain.ai/?q=1 à 10:30; fin...']): Book
{
    $book = Book::factory()->for(User::factory())->create(array_merge(['title' => 'Typo', 'language' => 'French'], $attributes));

    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Chapitre "un"',
        'type' => 'chapter',
        'position' => 1,
        'content' => ['type' => 'doc', 'content' => array_map(fn ($p) => [
            'type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $p]],
        ], $paragraphs)],
    ]);

    return $book->fresh();
}

/** Spaces made visible: _ no-break, ^ narrow no-break. */
function shown(string $s): string
{
    return str_replace(["\u{00A0}", "\u{202F}"], ['_', '^'], $s);
}

it('sets French text the French way and leaves addresses and times alone', function () {
    $count = 0;
    $out = Typography::text('Il a dit "bonjour" ! Quoi? Note: voir https://ecrivain.ai/?q=1 à 10:30; fin...', FR, $count);

    expect(shown($out))->toBe('Il a dit «_bonjour_»^! Quoi^? Note_: voir https://ecrivain.ai/?q=1 à 10:30^; fin…')
        ->and($count)->toBe(7);
});

it('spaces typed guillemets and colons, and turns a full space before ; ! ? into a thin one', function () {
    $count = 0;
    $out = Typography::text("« Déjà » et «non» ; l'homme -- vite - ok\u{00A0}!", FR, $count);

    expect(shown($out))->toBe('«_Déjà_» et «_non_»^; l’homme — vite – ok^!')
        ->and($count)->toBe(9);
});

it('curls English quotes, apostrophes, dashes and ellipses', function () {
    $count = 0;
    $out = Typography::text("She said \"it's 'fine'\" -- and left... ('Tis \"yes\") ---- rule", EN, $count);

    expect($out)->toBe('She said “it’s ‘fine’” — and left… (‘Tis “yes”) ---- rule')
        ->and($count)->toBe(10);
});

it('changes nothing the second time', function () {
    foreach ([FR, EN] as $rules) {
        $once = Typography::text('A "quote" here! And: there? -- ... «x»', $rules);
        $count = 0;

        expect(Typography::text($once, $rules, $count))->toBe($once)->and($count)->toBe(0);
    }
});

it('reads a paragraph as one run across marks, and never touches code', function () {
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Il dit "'],
            ['type' => 'text', 'text' => 'oui', 'marks' => [['type' => 'italic']]],
            ['type' => 'text', 'text' => '"! Tapez '],
            ['type' => 'text', 'text' => 'a"b?c', 'marks' => [['type' => 'code']]],
            ['type' => 'text', 'text' => '..'],
            ['type' => 'text', 'text' => '.', 'marks' => [['type' => 'bold']]],
            ['type' => 'footnote', 'attrs' => ['text' => 'Voir "là"!']],
        ]],
        ['type' => 'codeBlock', 'content' => [['type' => 'text', 'text' => 'echo "x"; exit!']]],
    ]];

    $count = 0;
    $out = Typography::document($doc, FR, $count);
    $p = $out['content'][0]['content'];

    expect(shown($p[0]['text']))->toBe('Il dit «_')
        ->and($p[1])->toBe(['type' => 'text', 'text' => 'oui', 'marks' => [['type' => 'italic']]])
        ->and(shown($p[2]['text']))->toBe('_»^! Tapez ')
        ->and($p[3]['text'])->toBe('a"b?c')
        // Three dots over two runs: one ellipsis, in the first.
        ->and($p[4]['text'])->toBe('…')
        ->and($p[5]['type'])->toBe('footnote')
        ->and(shown($p[5]['attrs']['text']))->toBe('Voir «_là_»^!')
        ->and(count($p))->toBe(6)
        ->and($out['content'][1])->toBe($doc['content'][1])
        ->and($count)->toBe(7);
});

it('takes the rules from the language until the author picks', function () {
    $french = typoBook();
    $english = typoBook(['language' => 'English (US)']);
    $kreyol = typoBook(['language' => 'Haitian Creole']);

    expect(Typography::resolve($french))->toMatchArray(FR + ['as_you_type' => false, 'apply_at_export' => false, 'language' => 'fr'])
        ->and(Typography::resolve($english))->toMatchArray(EN + ['language' => 'en-US'])
        ->and(Typography::resolve($kreyol))->toMatchArray(['quotes' => 'guillemets', 'french_spacing' => true, 'language' => 'ht']);

    $french->update(['settings' => ['typography' => ['quotes' => 'curly', 'french_spacing' => false]]]);

    expect(Typography::resolve($french->fresh()))->toMatchArray(['quotes' => 'curly', 'french_spacing' => false, 'dashes' => true]);
});

it('validates and merges the typography settings', function () {
    $book = typoBook();
    $user = $book->user;

    $this->actingAs($user)->patch("/books/{$book->id}", ['settings' => ['typography' => ['quotes' => 'smart']]])
        ->assertSessionHasErrors('settings.typography.quotes');
    $this->actingAs($user)->patch("/books/{$book->id}", ['settings' => ['typography' => ['as_you_type' => 'maybe']]])
        ->assertSessionHasErrors('settings.typography.as_you_type');
    $this->actingAs($user)->patch("/books/{$book->id}", ['settings' => ['spellcheck' => 'often']])
        ->assertSessionHasErrors('settings.spellcheck');

    $this->actingAs($user)->patch("/books/{$book->id}", ['settings' => ['typography' => ['as_you_type' => true]]])->assertSessionHasNoErrors();
    $this->actingAs($user)->patch("/books/{$book->id}", ['settings' => ['typography' => ['quotes' => 'none', 'dashes' => false]]])->assertSessionHasNoErrors();
    $this->actingAs($user)->patch("/books/{$book->id}", ['settings' => ['typography' => ['dashes' => null], 'spellcheck' => false]])->assertSessionHasNoErrors();

    expect($book->fresh()->settings()['typography'])->toBe([
        'as_you_type' => true, 'apply_at_export' => false, 'quotes' => 'none',
        'french_spacing' => null, 'dashes' => null, 'ellipsis' => null,
    ])->and($book->fresh()->settings()['spellcheck'])->toBeFalse();

    // Somebody else's book: no.
    $this->actingAs(User::factory()->create())
        ->patch("/books/{$book->id}", ['settings' => ['typography' => ['as_you_type' => false]]])
        ->assertForbidden();
});

it('gives the editor the language, spell-check and rules', function () {
    $book = typoBook(['language' => 'fr-CA', 'settings' => ['typography' => ['as_you_type' => true]]]);

    $this->actingAs($book->user)->get("/books/{$book->id}/edit")
        ->assertInertia(fn ($page) => $page
            ->where('book.language_code', 'fr-CA')
            ->where('book.spellcheck', true)
            ->where('book.typography.as_you_type', true)
            ->where('book.typography.quotes', 'guillemets'));
});

it('counts first, then fixes with a version of each chapter taken before', function () {
    $book = typoBook([], ['Quoi? "Oui"...', 'Rien à faire.']);
    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Propre', 'type' => 'chapter', 'position' => 2,
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Déjà propre.']]]]],
    ]);
    $section = $book->sections()->where('position', 1)->first();
    $before = $section->content;

    $preview = $this->actingAs($book->user)->getJson("/books/{$book->id}/typography/preview")->assertOk();

    // Title: "un" (2); text: ? (1), "Oui" (2), ... (1).
    expect($preview->json('changes'))->toBe(6)
        ->and($preview->json('chapters'))->toBe([['id' => $section->id, 'title' => 'Chapitre "un"', 'changes' => 6]])
        ->and($section->fresh()->content)->toBe($before)
        ->and($section->versions()->count())->toBe(0);

    $fix = $this->actingAs($book->user)->postJson("/books/{$book->id}/typography/fix")->assertOk();

    $section->refresh();
    $version = $section->versions()->first();

    expect($fix->json('changes'))->toBe(6)
        ->and(shown($section->content['content'][0]['content'][0]['text']))->toBe('Quoi^? «_Oui_»…')
        ->and(shown($section->title))->toBe('Chapitre «_un_»')
        ->and($version->reason)->toBe('typography')
        ->and($version->label)->toBe('Before typography fix (6 corrections)')
        ->and($version->content)->toBe($before)
        ->and($version->title)->toBe('Chapitre "un"')
        // The chapter with nothing to fix has no new version.
        ->and($book->sections()->where('position', 2)->first()->versions()->count())->toBe(0);

    expect($this->actingAs($book->user)->getJson("/books/{$book->id}/typography/preview")->json('changes'))->toBe(0);

    $this->actingAs(User::factory()->create())->postJson("/books/{$book->id}/typography/fix")->assertForbidden();
});

it('declares the book language in every export, including a language code', function () {
    $book = typoBook(['language' => 'fr-CA']);
    $exporter = app(Exporter::class);

    expect(Exporter::languageCode('fr-CA'))->toBe('fr-CA')
        ->and(Exporter::languageCode('PT-br'))->toBe('pt-BR')
        ->and(Exporter::languageCode('Italian'))->toBe('it')
        ->and(Exporter::languageCode('en'))->toBe('en-US')
        ->and(Exporter::languageCode('Klingon'))->toBe('en-US');

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($exporter->generate($book, 'epub')->path));
    expect($zip->getFromName('OEBPS/content.opf'))->toContain('<dc:language>fr-CA</dc:language>')
        ->and($zip->getFromName('OEBPS/ch1.xhtml'))->toContain('xml:lang="fr-CA"');
    $zip->close();

    expect(Storage::disk('local')->get($exporter->generate($book, 'html')->path))->toContain('<html lang="fr-CA">');
});

it('sets French spacing in the EPUB only when the book opts in, never touching the manuscript', function () {
    $book = typoBook();
    $exporter = app(Exporter::class);
    $chapter = function (Book $book) use ($exporter) {
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($exporter->generate($book, 'epub')->path));
        $xhtml = $zip->getFromName('OEBPS/ch1.xhtml');
        $zip->close();

        return $xhtml;
    };

    $plain = $chapter($book);
    expect($plain)->toContain('dit &quot;bonjour&quot; ! Quoi?')->not->toContain("\u{202F}");

    $book->update(['settings' => ['typography' => ['apply_at_export' => true]]]);
    $before = $book->sections()->first()->content;
    $set = $chapter($book->fresh());

    expect(shown($set))->toContain('dit «_bonjour_»^! Quoi^? Note_: voir https://ecrivain.ai/?q=1 à 10:30^; fin…')
        ->toContain('Chapitre «_un_»')
        ->and($book->sections()->first()->content)->toBe($before);
});

it('prints a narrow no-break space as a full one in a face without it', function () {
    $book = typoBook([], ["Quoi\u{202F}? Oui\u{00A0}!"]);

    $html = app(PrintBook::class)->html($book, $book->sections()->get(), 'fr');

    expect($html)->toContain("Quoi\u{00A0}? Oui\u{00A0}!")->not->toContain("\u{202F}");
});

it('exports an untouched book exactly as before', function () {
    $book = typoBook(['language' => 'English (US)'], ['She said "it\'s fine" -- and left...']);
    $exporter = app(Exporter::class);
    $html = fn (Book $b) => preg_replace('/\d{4}-\d{2}-\d{2}T[\d:+.Z-]+/', '', Storage::disk('local')->get($exporter->generate($b, 'html')->path));

    $untouched = $html($book);

    expect($untouched)->toContain('She said &quot;it')->toContain('fine&quot; -- and left...')->not->toContain('“');

    // Rules chosen but not applied at export: the same file.
    $book->update(['settings' => ['typography' => ['as_you_type' => true, 'quotes' => 'curly']]]);
    expect($html($book->fresh()))->toBe($untouched);
});
