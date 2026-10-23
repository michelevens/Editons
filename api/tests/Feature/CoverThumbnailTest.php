<?php

use App\Models\Book;
use App\Models\User;
use App\Publishing\CoverRenderer;
use Illuminate\Support\Facades\Storage;

/**
 * The shelf-sized cover.
 *
 * The library drew 104px covers from the full 1600 × 2560 files, which was
 * 12–15 MB of JPEG for one visit. The thumbnail is the same cover scaled to
 * 320px, stored beside it and named by the same design hash.
 */
function thumbBook(User $owner): Book
{
    return $owner->books()->create(['title' => 'Shelf Life', 'author_name' => 'A Writer']);
}

it('serves a 320px thumbnail of the cover, and the full cover without size', function () {
    Storage::fake('files');

    $user = User::factory()->create();
    $book = thumbBook($user);
    $cover = $book->coverFor();

    expect($cover['thumb'])->toContain("/books/{$book->id}/cover/image?")
        ->and($cover['thumb'])->toContain('size=thumb');

    $thumb = $this->actingAs($user)->get($cover['thumb']);
    $thumb->assertOk()->assertHeader('Content-Type', 'image/jpeg');

    $bytes = $thumb->streamedContent();
    [$width, $height] = getimagesizefromstring($bytes);

    expect($width)->toBe(CoverRenderer::THUMB_WIDTH)
        ->and($height)->toBe(512)
        // A shelf of covers in tens of kilobytes, not hundreds.
        ->and(strlen($bytes))->toBeLessThan(80_000);

    $full = $this->actingAs($user)->get($cover['url'])->streamedContent();
    expect(getimagesizefromstring($full)[0])->toBe(CoverRenderer::WIDTH);

    Storage::disk('files')->assertExists(app(CoverRenderer::class)->thumbPath($book));
});

it('keeps one thumbnail per book, replacing it when the design changes', function () {
    Storage::fake('files');

    $user = User::factory()->create();
    $book = thumbBook($user);
    $renderer = app(CoverRenderer::class);

    $first = $renderer->ensureThumb($book);

    $book->update(['cover' => ['palette' => 2]]);
    $second = $renderer->ensureThumb($book->fresh());

    expect($second)->not->toBe($first);
    Storage::disk('files')->assertMissing($first);
    Storage::disk('files')->assertExists($second);
});

it('does not show somebody else\'s cover thumbnail', function () {
    Storage::fake('files');

    $book = thumbBook(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->get($book->coverFor()['thumb'])
        ->assertForbidden();
});

it('gives the library the thumbnail address', function () {
    Storage::fake('files');

    $user = User::factory()->create();
    $book = thumbBook($user);

    $this->actingAs($user)->get('/books')->assertInertia(
        fn ($page) => $page->where('books.0.cover.thumb', $book->coverFor()['thumb']),
    );
});
