<?php

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\DocumentRenderer;
use App\Publishing\Exporter;
use App\Publishing\PrintBook;
use Illuminate\Support\Facades\Storage;

/**
 * Links and pictures in a manuscript are untrusted input.
 *
 * A collaborator, or an imported .ecrivain file, can put any href in a link
 * mark. "javascript:" in the Design preview ran on the app's own origin, and
 * the same href went into every HTML and EPUB export.
 */
function linkedBook(User $user, array $nodes): Book
{
    $book = Book::factory()->for($user)->create(['title' => 'Links']);

    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Chapter 1',
        'type' => 'chapter',
        'position' => 1,
        'content' => ['type' => 'doc', 'content' => $nodes],
    ]);

    return $book->fresh();
}

function unsafeLink(string $text, string $href): array
{
    return ['type' => 'text', 'text' => $text, 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]];
}

function hostileLinks(): array
{
    return [['type' => 'paragraph', 'content' => [
        unsafeLink('JS', 'javascript:alert(document.domain)'),
        ['type' => 'text', 'text' => ' '],
        unsafeLink('TAB', " java\tscript:alert(1)"),
        ['type' => 'text', 'text' => ' '],
        unsafeLink('DATA', 'data:text/html,<script>alert(1)</script>'),
        ['type' => 'text', 'text' => ' '],
        unsafeLink('VB', 'VBScript:msgbox(1)'),
        ['type' => 'text', 'text' => ' '],
        unsafeLink('WEB', 'https://example.org/a?b=1'),
        ['type' => 'text', 'text' => ' '],
        unsafeLink('MAIL', 'mailto:someone@example.org'),
        ['type' => 'text', 'text' => ' '],
        unsafeLink('ANCHOR', '#section-4'),
    ]]];
}

function expectNoHostileLinks(string $out): void
{
    expect($out)->not->toContain('javascript:')
        ->not->toContain('script:alert')
        ->not->toContain('data:text/html')
        ->not->toContain('VBScript')
        // The words stay; only the link goes.
        ->toContain('JS')
        ->toContain('TAB')
        ->toContain('https://example.org/a?b=1')
        ->toContain('mailto:someone@example.org')
        ->toContain('#section-4');
}

it('drops script links from the Design preview', function () {
    $user = User::factory()->create();
    $book = linkedBook($user, hostileLinks());

    $html = $this->actingAs($user)->getJson("/books/{$book->id}/design")->assertOk()->json('preview.html');

    expectNoHostileLinks($html);
    expect($html)->toContain('<a href="https://example.org/a?b=1" rel="noopener">WEB</a>');
});

it('drops script links from the HTML, EPUB, Markdown and print exports', function () {
    $book = linkedBook(User::factory()->create(), hostileLinks());
    $exporter = app(Exporter::class);

    expectNoHostileLinks(Storage::disk('local')->get($exporter->generate($book, 'html')->path));
    expectNoHostileLinks(Storage::disk('local')->get($exporter->generate($book, 'md')->path));
    expectNoHostileLinks(app(PrintBook::class)->html($book, $book->sections()->get(), 'en'));

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($exporter->generate($book, 'epub')->path));
    expectNoHostileLinks($zip->getFromName('OEBPS/ch1.xhtml'));
    $zip->close();
});

it('only shows pictures from this site or inline raster data', function () {
    $book = linkedBook(User::factory()->create(), [
        ['type' => 'image', 'attrs' => ['src' => 'javascript:alert(1)', 'alt' => 'Script']],
        ['type' => 'image', 'attrs' => ['src' => 'http://127.0.0.1:9911/pixel.png', 'alt' => 'Tracker']],
        ['type' => 'image', 'attrs' => ['src' => '//evil.example/pixel.png', 'alt' => 'Protocol relative']],
        ['type' => 'image', 'attrs' => ['src' => 'file:///etc/passwd', 'alt' => 'Local file']],
        ['type' => 'image', 'attrs' => ['src' => 'data:image/svg+xml;base64,PHN2Zy8+', 'alt' => 'SVG']],
        ['type' => 'image', 'attrs' => ['src' => 'data:image/png;base64,iVBORw0KGgo=', 'alt' => 'Inline']],
        ['type' => 'image', 'attrs' => ['src' => '/books/1/images/abcdefgh.png', 'alt' => 'Stored']],
    ]);

    $html = app(DocumentRenderer::class)->html($book->sections()->first());

    expect($html)->not->toContain('src="javascript')
        ->not->toContain('127.0.0.1')
        ->not->toContain('evil.example')
        ->not->toContain('file:')
        ->not->toContain('image/svg+xml')
        ->toContain('[Image: Tracker]')
        ->toContain('src="data:image/png;base64,iVBORw0KGgo="')
        ->toContain('src="/books/1/images/abcdefgh.png"');
});
