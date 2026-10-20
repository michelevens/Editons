<?php

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\Exporter;
use App\Publishing\PrintBook;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Pictures in a manuscript: upload, serve, and embed in every export.
 */
function pictureBook(User $user, ?string $src = null, ?Book $book = null): Book
{
    $book ??= Book::factory()->for($user)->create(['title' => 'Figures']);
    $book->sections()->delete();

    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Chapter 1: Results',
        'type' => 'chapter',
        'content' => ['type' => 'doc', 'content' => array_values(array_filter([
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'The response rate by week.']]],
            $src ? ['type' => 'image', 'attrs' => ['src' => $src, 'alt' => 'Bar chart of weekly response rates']] : null,
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Figure 1.1', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' Weekly response rate.'],
            ]],
        ]))],
    ]);

    return $book->fresh();
}

function uploadPicture($test, User $user, Book $book): array
{
    return $test->actingAs($user)
        ->post("/books/{$book->id}/images", ['image' => UploadedFile::fake()->image('chart.png', 800, 500)])
        ->assertCreated()
        ->json();
}

it('uploads a picture and serves it only to people who can see the book', function () {
    $user = User::factory()->create();
    $book = pictureBook($user);

    $image = uploadPicture($this, $user, $book);

    expect($image['src'])->toMatch('#^/books/'.$book->id.'/images/[a-z0-9]+\.png$#')
        ->and($image['width'])->toBe(800);

    $this->actingAs($user)->get($image['src'])->assertOk()->assertHeader('Content-Type', 'image/png');
    $this->actingAs(User::factory()->create())->get($image['src'])->assertForbidden();
});

it('refuses SVG and other non-pictures', function () {
    $user = User::factory()->create();
    $book = pictureBook($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/images", ['image' => UploadedFile::fake()->create('x.svg', 4, 'image/svg+xml')])
        ->assertUnprocessable();
});

it('will not serve a path outside the book\'s images', function () {
    $user = User::factory()->create();
    $book = pictureBook($user);

    $this->actingAs($user)->get("/books/{$book->id}/images/..%2F..%2F.env")->assertNotFound();
});

it('embeds pictures in the PDF, EPUB, Word and single HTML exports', function () {
    $user = User::factory()->create();
    $book = pictureBook($user);
    $image = uploadPicture($this, $user, $book);
    $book = pictureBook($user, $image['src'], $book);
    $exporter = app(Exporter::class);

    // PDF: typeset with the picture as data, the caption as a caption.
    $html = app(PrintBook::class)->html($book, $book->sections()->get(), 'en');
    expect($html)->toContain('src="data:image/png;base64,')
        ->and($html)->toContain('<p class="caption">');

    // EPUB: the picture is in the package and the manifest.
    $epub = $exporter->generate($book, 'epub');
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($epub->path));
    $name = basename($image['src']);
    expect($zip->getFromName("OEBPS/images/{$name}"))->not->toBeFalse()
        ->and($zip->getFromName('OEBPS/content.opf'))->toContain('href="images/'.$name.'" media-type="image/png"')
        ->and($zip->getFromName('OEBPS/ch1.xhtml'))->toContain('src="images/'.$name.'"');
    $zip->close();

    // Word: a drawing with its description, the picture as a media part.
    $docx = $exporter->generate($book, 'docx');
    $zip->open(Storage::disk('local')->path($docx->path));
    $document = $zip->getFromName('word/document.xml');
    expect($document)->toContain('<a:blip r:embed="rIdImg1"/>')
        ->and($document)->toContain('descr="Bar chart of weekly response rates"')
        ->and($zip->getFromName('word/media/image1.png'))->not->toBeFalse()
        ->and($zip->getFromName('word/_rels/document.xml.rels'))->toContain('Target="media/image1.png"')
        ->and($zip->getFromName('[Content_Types].xml'))->toContain('<Default Extension="png" ContentType="image/png"/>')
        ->and(simplexml_load_string($document))->not->toBeFalse();
    $zip->close();

    // One-file HTML carries the picture inside it.
    $single = $exporter->generate($book, 'html');
    expect(Storage::disk('local')->get($single->path))->toContain('src="data:image/png;base64,');
});

it('does not embed another book\'s picture', function () {
    // A pasted src naming someone else's book must not pull their image
    // into this export.
    $owner = User::factory()->create();
    $other = pictureBook($owner);
    $image = uploadPicture($this, $owner, $other);

    $book = pictureBook(User::factory()->create(), $image['src']);
    $html = app(PrintBook::class)->html($book, $book->sections()->get(), 'en');

    expect($html)->not->toContain('data:image/png');
});
