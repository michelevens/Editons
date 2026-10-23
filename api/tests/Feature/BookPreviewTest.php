<?php

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\Exporter;
use Illuminate\Support\Facades\Storage;

/*
 * The full-screen preview: the book as the EPUB and the printed book set
 * it, a page at a time, with what the server can see wrong on each.
 */

function pvBook(): Book
{
    $owner = User::factory()->create();
    $book = Book::factory()->for($owner)->create(['title' => 'The Lighthouse Year', 'author_name' => 'Ada Lane']);
    $book->sections()->delete();

    $paragraph = fn (string $text) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];

    $book->sections()->create([
        'type' => 'chapter',
        'title' => 'Arrival',
        'position' => ManuscriptSection::POSITION_STEP,
        'content' => ['type' => 'doc', 'content' => [$paragraph('The keeper came in March, when the ice let go.')]],
    ]);
    $book->sections()->create([
        'type' => 'chapter',
        'title' => 'Storms',
        'position' => 2 * ManuscriptSection::POSITION_STEP,
        'content' => ['type' => 'doc', 'content' => [$paragraph('By October the lamp burned through the afternoons.')]],
    ]);

    return $book->fresh();
}

it('lists the reader documents in the EPUB order and the printed blocks', function () {
    $book = pvBook();
    $chapters = $book->sections()->orderBy('position')->pluck('id');

    $response = $this->actingAs($book->user)->getJson("/books/{$book->id}/preview")->assertOk();

    $reader = collect($response->json('reader.items'));
    expect($reader->where('kind', 'chapter')->pluck('key')->all())
        ->toBe(["s{$chapters[0]}", "s{$chapters[1]}"])
        ->and($reader->pluck('key'))->toContain('titlepage')
        ->and($response->json('reader.css'))->toContain('line-height');

    $print = collect($response->json('print.blocks'));
    expect($print->pluck('kind')->first())->toBe('half-title')
        ->and($print->firstWhere('key', "s{$chapters[0]}")['label'])->toBe('Chapter One')
        ->and($response->json('print.page.width'))->toBe(6)
        ->and($response->json('print.page.height'))->toBe(9)
        ->and($response->json('print.css'))->toContain('.opener-title');
});

it('sends a chapter exactly as the EPUB sets it', function () {
    $book = pvBook();
    $first = $book->sections()->orderBy('position')->first();

    $html = $this->actingAs($book->user)
        ->getJson("/books/{$book->id}/preview/page?mode=reader&key=s{$first->id}")
        ->assertOk()
        ->json('html');

    $export = app(Exporter::class)->generate($book, 'epub', $book->user_id);
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->path));
    $file = collect(range(0, $zip->numFiles - 1))
        ->map(fn ($i) => $zip->getFromIndex($i))
        ->first(fn ($xhtml) => str_contains($xhtml, 'the ice let go'));

    expect($html)->toContain('the ice let go')
        ->and($file)->toContain($html);
});

it('sends a printed block as the PDF HTML sets it, pictures by link', function () {
    $book = pvBook();
    Storage::disk('files')->put("images/book-{$book->id}/abcdefgh12.png", base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
    $first = $book->sections()->orderBy('position')->first();
    $first->update(['content' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'The keeper came in March.']]],
        ['type' => 'image', 'attrs' => ['src' => "/books/{$book->id}/images/abcdefgh12.png", 'alt' => 'The lamp']],
    ]]]);

    $page = $this->actingAs($book->user)
        ->getJson("/books/{$book->id}/preview/page?mode=print&key=s{$first->id}")
        ->assertOk();

    expect($page->json('html'))
        ->toContain('class="opener-title"')
        ->toContain('Arrival')
        ->toContain("src=\"/books/{$book->id}/images/abcdefgh12.png\"")
        ->not->toContain('data:image/png;base64')
        ->and($page->json('warnings'))->toBe([]);

    // The title page, by what it is rather than by a section.
    $this->actingAs($book->user)
        ->getJson("/books/{$book->id}/preview/page?mode=print&key=title-page")
        ->assertOk()
        ->assertJsonPath('html', fn ($html) => str_contains($html, 'The Lighthouse Year'));
});

it('reports a missing picture and a table too wide for a phone, and marks them', function () {
    $cell = fn (string $text) => ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    $book = pvBook();
    $book->sections()->orderBy('position')->first()->update(['content' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Logbook.']]],
        ['type' => 'image', 'attrs' => ['src' => "/books/{$book->id}/images/zzzzzzzz99.png", 'alt' => '']],
        ['type' => 'table', 'content' => [
            ['type' => 'tableRow', 'content' => array_map($cell, ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'])],
        ]],
    ]]]);
    $first = $book->sections()->orderBy('position')->first();

    $page = $this->actingAs($book->user)
        ->getJson("/books/{$book->id}/preview/page?mode=reader&key=s{$first->id}")
        ->assertOk();

    expect(collect($page->json('warnings'))->pluck('type')->all())->toBe(['missing-image', 'wide-table'])
        ->and($page->json('html'))->toContain('data-pv-warn="missing-image"')
        ->toContain('data-pv-warn="wide-table"');

    // Print sets a table to the measure; only the picture is certain there.
    $print = $this->actingAs($book->user)
        ->getJson("/books/{$book->id}/preview/page?mode=print&key=s{$first->id}")
        ->assertOk();

    expect(collect($print->json('warnings'))->pluck('type')->all())->toBe(['missing-image']);
});

it('opens to a reviewer and to nobody outside the book', function () {
    $book = pvBook();
    $reviewer = User::factory()->create();
    $book->members()->create(['email' => $reviewer->email, 'user_id' => $reviewer->id, 'role' => 'reviewer', 'status' => 'active']);
    $first = $book->sections()->orderBy('position')->first();

    $this->actingAs($reviewer)->getJson("/books/{$book->id}/preview")->assertOk();
    $this->actingAs($reviewer)->getJson("/books/{$book->id}/preview/page?mode=reader&key=s{$first->id}")->assertOk();

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->getJson("/books/{$book->id}/preview")->assertForbidden();
    $this->actingAs($stranger)->getJson("/books/{$book->id}/preview/page?mode=reader&key=s{$first->id}")->assertForbidden();
});

it('refuses a page the book does not have, or a mode that does not exist', function () {
    $book = pvBook();

    $this->actingAs($book->user)->getJson("/books/{$book->id}/preview/page?mode=reader&key=s999999")->assertNotFound();
    $this->actingAs($book->user)->getJson("/books/{$book->id}/preview/page?mode=pdf&key=toc")->assertUnprocessable();
    $this->actingAs($book->user)->getJson("/books/{$book->id}/preview/page?mode=reader&key=../x")->assertUnprocessable();
});

it('serves the print faces and nothing else from the fonts folder', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/preview/fonts/EBGaramond-Regular.ttf')
        ->assertOk()
        ->assertHeader('Content-Type', 'font/ttf');

    $this->actingAs($user)->get('/preview/fonts/OFL-EBGaramond.ttf')->assertNotFound();
});
