<?php

use App\Jobs\GenerateExport;
use App\Models\Book;
use App\Models\BookExport;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\Exporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * The publishing chain over HTTP.
 *
 * The authorisation tests matter most: a reviewer invited to comment must not
 * be able to submit somebody's book to a store under their name.
 */
function chapteredBook(User $owner, array $attributes = []): Book
{
    $book = $owner->books()->create(array_merge([
        'title' => 'A Practical Guide',
        'author_name' => 'A Writer',
        'list_price' => 14.99,
        'meta' => [
            'description' => str_repeat('A clear and useful description. ', 6),
            'category' => 'Business & Economics / Small Business',
        ],
    ], $attributes));

    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Chapter One',
        'type' => 'chapter',
        'position' => 0,
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Prose that belongs in a book.']],
        ]]],
    ]);

    $book->forceFill(['word_count' => 20_000])->save();

    return $book->fresh();
}

// --- Publishing ---------------------------------------------------------

it('shows the publishing screen with blockers listed up front', function () {
    // An author should be able to see what is left to do before trying, not
    // only after a rejected submission.
    $user = User::factory()->create();
    $book = chapteredBook($user, ['meta' => []]);

    $response = $this->actingAs($user)
        ->getJson("/books/{$book->id}/publishing")
        ->assertOk();

    expect($response->json('blockers'))->toHaveKey('description')
        ->and($response->json('formats'))->toHaveCount(count(BookExport::FORMATS))
        ->and($response->json('channels'))->not->toBeEmpty();
});

it('saves store metadata', function () {
    $user = User::factory()->create();
    $book = chapteredBook($user);

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/publishing", [
            'description' => str_repeat('A new description for the listing. ', 5),
            'category' => 'Medical / Practice Management',
            'list_price' => 19.99,
        ])
        ->assertOk();

    $book->refresh();

    expect($book->meta['category'])->toBe('Medical / Practice Management')
        ->and((float) $book->list_price)->toBe(19.99);
});

it('refuses a category that is not in the store taxonomy', function () {
    // A typed category is a rejected submission, so it is a fixed list.
    $user = User::factory()->create();
    $book = chapteredBook($user);

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/publishing", ['list_price' => -5])
        ->assertStatus(422);
});

it('will not submit a book that is not ready, and says why per field', function () {
    $user = User::factory()->create();
    $book = chapteredBook($user, ['meta' => [], 'list_price' => null]);
    $book->channels()->create(['channel' => 'kobo', 'format' => 'ebook']);

    $response = $this->actingAs($user)
        ->postJson("/books/{$book->id}/submit")
        ->assertStatus(422);

    expect($response->json('errors'))->toHaveKeys(['description', 'category', 'list_price']);
});

it('submits to the selected stores and marks them in review, not live', function () {
    // A store decides when a book goes on sale. Claiming live would put a
    // date on the screen that never happened.
    $user = User::factory()->create();
    $book = chapteredBook($user);
    $book->channels()->create(['channel' => 'amazon_kdp', 'format' => 'ebook']);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/submit")
        ->assertOk();

    $channel = $book->channels()->first();

    expect($channel->status)->toBe('in_review')
        ->and($channel->submitted_at)->not->toBeNull()
        ->and($channel->went_live_at)->toBeNull()
        ->and($book->fresh()->status)->toBe('in_review');
});

it('refuses to submit when no store is selected', function () {
    $user = User::factory()->create();
    $book = chapteredBook($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/submit")
        ->assertStatus(422);
});

it('turns a store on and off', function () {
    $user = User::factory()->create();
    $book = chapteredBook($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/channels", ['channel' => 'kobo', 'enabled' => true])
        ->assertOk();

    expect($book->channels()->count())->toBe(1);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/channels", ['channel' => 'kobo', 'enabled' => false])
        ->assertOk();

    expect($book->channels()->count())->toBe(0);
});

it('will not unselect a store the book is live in', function () {
    // The listing stays up until the store is told. Unticking a box must not
    // imply the book was withdrawn.
    $user = User::factory()->create();
    $book = chapteredBook($user);

    $channel = $book->channels()->create(['channel' => 'kobo', 'format' => 'ebook']);
    $channel->forceFill([
        'status' => 'live',
        'submitted_at' => now()->subWeek(),
        'went_live_at' => now()->subDay(),
    ])->save();

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/channels", ['channel' => 'kobo', 'enabled' => false])
        ->assertStatus(422);

    expect($book->channels()->count())->toBe(1);
});

it('unpublishes a live book from one store', function () {
    $user = User::factory()->create();
    $book = chapteredBook($user);

    $channel = $book->channels()->create(['channel' => 'kobo', 'format' => 'ebook']);
    $channel->forceFill([
        'status' => 'live',
        'submitted_at' => now()->subWeek(),
        'went_live_at' => now()->subDay(),
    ])->save();

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/channels/{$channel->id}/unpublish")
        ->assertOk();

    expect($channel->fresh()->status)->toBe('unpublished')
        ->and($channel->fresh()->went_live_at)->toBeNull()
        // Kept: it is a record of what happened.
        ->and($channel->fresh()->submitted_at)->not->toBeNull();
});

it('generates a PDF or EPUB on a worker and lists it in history', function () {
    // EPUB is on every plan, but subscribed() keeps this about export
    // history rather than entitlements.
    Queue::fake();
    $user = User::factory()->subscribed()->create();
    $book = chapteredBook($user);

    // Answered at once: a long book takes minutes to lay out, longer than
    // any web server waits.
    $response = $this->actingAs($user)
        ->postJson("/books/{$book->id}/exports", ['format' => 'epub'])
        ->assertStatus(202);

    expect($response->json('export.status'))->toBe('generating')
        ->and($response->json('book.exports'))->toHaveCount(1);

    Queue::assertPushed(GenerateExport::class, fn ($job) => $job->exportId === $response->json('export.id'));

    // The worker's turn.
    (new GenerateExport($response->json('export.id')))->handle(app(Exporter::class));

    $export = BookExport::find($response->json('export.id'));
    expect($export->status)->toBe('ready')
        ->and($export->byte_size)->toBeGreaterThan(0);
});

it('records a job that died as failed, not as generating for ever', function () {
    $user = User::factory()->create();
    $export = app(Exporter::class)->start(chapteredBook($user), 'pdf', $user->id);

    (new GenerateExport($export->id))->failed(new RuntimeException('Killed by the timeout'));

    expect($export->fresh()->status)->toBe('failed');
});

it('still makes the small formats while the author waits', function () {
    Queue::fake();
    $user = User::factory()->subscribed()->create();
    $book = chapteredBook($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/exports", ['format' => 'md'])
        ->assertOk()
        ->assertJsonPath('export.status', 'ready');

    Queue::assertNothingPushed();
});

it('explains an empty manuscript rather than failing with a 500', function () {
    $user = User::factory()->create();
    $book = $user->books()->create(['title' => 'Nothing Yet']);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/exports", ['format' => 'epub'])
        ->assertStatus(422)
        ->assertJsonFragment(['message' => 'There is nothing to export yet. Write a chapter first.']);
});

it('downloads a generated file', function () {
    // subscribed(): markdown is not on the free plan's format list, so
    // generating it is refused before there is anything to download.
    $user = User::factory()->subscribed()->create();
    $book = chapteredBook($user);

    $export = $this->actingAs($user)
        ->postJson("/books/{$book->id}/exports", ['format' => 'md'])
        ->json('export.id');

    $this->actingAs($user)
        ->get("/books/{$book->id}/exports/{$export}/download")
        ->assertOk()
        ->assertDownload();
});

it('will not serve another author\'s export', function () {
    // These are unpublished manuscripts. A guessable id must not hand one
    // to somebody else.
    // subscribed(): markdown needs a plan that includes it, or the export
    // is refused and there is nothing to attempt to steal.
    $owner = User::factory()->subscribed()->create();
    $stranger = User::factory()->create();
    $book = chapteredBook($owner);

    $export = $this->actingAs($owner)
        ->postJson("/books/{$book->id}/exports", ['format' => 'md'])
        ->json('export.id');

    $this->actingAs($stranger)
        ->get("/books/{$book->id}/exports/{$export}/download")
        ->assertForbidden();
});

it('will not serve an export belonging to a different book', function () {
    $user = User::factory()->subscribed()->create();
    $first = chapteredBook($user);
    $second = chapteredBook($user, ['title' => 'Another Book']);

    $export = $this->actingAs($user)
        ->postJson("/books/{$first->id}/exports", ['format' => 'md'])
        ->json('export.id');

    $this->actingAs($user)
        ->get("/books/{$second->id}/exports/{$export}/download")
        ->assertNotFound();
});

it('stops a reviewer from submitting somebody else\'s book', function () {
    // The whole point of the reviewer role: read and comment, not publish.
    $owner = User::factory()->create();
    $reviewer = User::factory()->create();
    $book = chapteredBook($owner);
    $book->channels()->create(['channel' => 'kobo', 'format' => 'ebook']);

    $book->members()->create([
        'user_id' => $reviewer->id,
        'email' => $reviewer->email,
        'role' => 'reviewer',
        'status' => 'active',
    ]);

    $this->actingAs($reviewer)
        ->postJson("/books/{$book->id}/submit")
        ->assertForbidden();

    expect($book->channels()->first()->status)->toBe('not_submitted');
});

it('lets a reviewer read the publishing screen but not change it', function () {
    $owner = User::factory()->create();
    $reviewer = User::factory()->create();
    $book = chapteredBook($owner);

    $book->members()->create([
        'user_id' => $reviewer->id,
        'email' => $reviewer->email,
        'role' => 'reviewer',
        'status' => 'active',
    ]);

    // Reading the screen is an update-gated action here, because it reports
    // submission state and blockers. A reviewer has no business there.
    $this->actingAs($reviewer)
        ->getJson("/books/{$book->id}/publishing")
        ->assertForbidden();
});

// --- Design -------------------------------------------------------------

it('shows layout with defaults and a real chapter to preview', function () {
    // Filler prose tells an author nothing about how their book will set.
    $user = User::factory()->create();
    $book = chapteredBook($user);

    $response = $this->actingAs($user)
        ->getJson("/books/{$book->id}/design")
        ->assertOk();

    expect($response->json('layout.trim'))->toBe('6 x 9 in')
        ->and($response->json('preview.html'))->toContain('Prose that belongs in a book')
        ->and($response->json('palettes'))->not->toBeEmpty();
});

it('saves a layout change', function () {
    $user = User::factory()->create();
    $book = chapteredBook($user);

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/layout", [
            'trim' => '5 x 8 in',
            'size' => 10.5,
            'dropcap' => true,
        ])
        ->assertOk();

    expect($book->fresh()->layout())
        ->toMatchArray(['trim' => '5 x 8 in', 'size' => 10.5, 'dropcap' => true]);
});

it('refuses a font size that would make a print interior unreadable', function () {
    $user = User::factory()->create();
    $book = chapteredBook($user);

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/layout", ['size' => 42])
        ->assertStatus(422);
});

it('resets layout to the defaults', function () {
    $user = User::factory()->create();
    $book = chapteredBook($user);
    $book->update(['layout' => ['trim' => '8.5 x 11 in', 'size' => 14]]);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/layout/reset")
        ->assertOk();

    // toEqual rather than toBe: the point is the values, and a jsonb round
    // trip returns 11 where the constant says 11.0.
    expect($book->fresh()->layout())->toEqual(Book::DEFAULT_LAYOUT);
});

it('saves cover text and palette', function () {
    $user = User::factory()->create();
    $book = chapteredBook($user);

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/cover", [
            'title' => 'A PRACTICAL GUIDE',
            'palette' => 2,
        ])
        ->assertOk();

    expect($book->fresh()->cover)
        ->toMatchArray(['title' => 'A PRACTICAL GUIDE', 'palette' => 2]);
});

it('rejects an SVG upload, which can carry script', function () {
    // Laravel's `image` rule accepts SVG. A cover is served back to anyone
    // viewing the book, so the allowed types are named explicitly.
    Storage::fake('files');

    $user = User::factory()->create();
    $book = chapteredBook($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/cover/artwork", [
            'artwork' => UploadedFile::fake()
                ->createWithContent('cover.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ])
        ->assertStatus(422);
});

// --- Rights -------------------------------------------------------------

it('lists every edition format and the unassigned pool', function () {
    $user = User::factory()->create();
    $book = chapteredBook($user);
    $user->isbns()->create(['isbn' => '9780306406157']);

    $response = $this->actingAs($user)
        ->getJson("/books/{$book->id}/rights")
        ->assertOk();

    expect($response->json('editions'))->toHaveCount(count(Book::EDITION_FORMATS))
        ->and($response->json('pool'))->toHaveCount(1)
        ->and($response->json('checklist'))->not->toBeEmpty();
});

it('assigns a pooled ISBN to an edition', function () {
    $user = User::factory()->create();
    $book = chapteredBook($user);
    $isbn = $user->isbns()->create(['isbn' => '9780306406157']);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/isbns", [
            'edition_format' => 'ebook',
            'isbn_id' => $isbn->id,
        ])
        ->assertOk();

    expect($isbn->fresh()->book_id)->toBe($book->id)
        // The ebook number goes into the EPUB and the store listing.
        ->and($book->fresh()->isbn)->toBe('9780306406157');
});

it('will not assign a number from somebody else\'s pool', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $book = chapteredBook($user);

    $theirs = $stranger->isbns()->create(['isbn' => '9780306406157']);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/isbns", [
            'edition_format' => 'ebook',
            'isbn_id' => $theirs->id,
        ])
        ->assertNotFound();

    expect($theirs->fresh()->book_id)->toBeNull();
});

it('rejects a mistyped ISBN by its checksum', function () {
    // A wrong number on a cover points readers at a different book.
    $user = User::factory()->create();
    $book = chapteredBook($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/isbns", [
            'edition_format' => 'ebook',
            'isbn' => '9780306406158',
        ])
        ->assertStatus(422);
});

it('refuses a second ISBN for the same edition', function () {
    $user = User::factory()->create();
    $book = chapteredBook($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/isbns", [
        'edition_format' => 'ebook', 'isbn' => '9780306406157',
    ])->assertOk();

    $this->actingAs($user)->postJson("/books/{$book->id}/isbns", [
        'edition_format' => 'ebook', 'isbn' => '9781861972712',
    ])->assertStatus(422);
});

it('returns an unassigned number to the pool', function () {
    $user = User::factory()->create();
    $book = chapteredBook($user);
    $isbn = $user->isbns()->create(['isbn' => '9780306406157']);

    $this->actingAs($user)->postJson("/books/{$book->id}/isbns", [
        'edition_format' => 'ebook', 'isbn_id' => $isbn->id,
    ])->assertOk();

    $this->actingAs($user)
        ->deleteJson("/books/{$book->id}/isbns/{$isbn->id}")
        ->assertOk();

    expect($isbn->fresh()->book_id)->toBeNull()
        ->and($isbn->fresh()->edition_format)->toBeNull()
        ->and($book->fresh()->isbn)->toBeNull();
});

it('adds a batch to the pool and reports the ones it rejected', function () {
    // Pasting ten numbers and losing nine to one typo would be worse than
    // reporting partial success.
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/isbns', ['isbns' => [
            '9780306406157',
            '9780306406158',
            '978-1-861972-71-2',
        ]])
        ->assertOk();

    expect($response->json('added'))->toBe(2)
        ->and($response->json('rejected'))->toHaveCount(1);
});

it('inserts a copyright page and sees the checklist update', function () {
    // The two computed items are derived from the manuscript, so inserting
    // the page must satisfy both without storing anything.
    $user = User::factory()->create();
    $book = chapteredBook($user);

    $response = $this->actingAs($user)
        ->postJson("/books/{$book->id}/copyright-page")
        ->assertOk();

    $checklist = collect($response->json('checklist'))->keyBy('key');

    expect($checklist['copyright_page']['done'])->toBeTrue()
        ->and($checklist['disclaimer']['done'])->toBeTrue()
        ->and($book->sections()->where('type', 'front_matter')->count())->toBe(1);
});

it('replaces the copyright page rather than adding a second', function () {
    $user = User::factory()->create();
    $book = chapteredBook($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/copyright-page")->assertOk();
    $this->actingAs($user)->postJson("/books/{$book->id}/copyright-page")->assertOk();

    expect($book->sections()->where('title', 'Copyright')->count())->toBe(1);
});

it('records a rights attestation', function () {
    $user = User::factory()->create();
    $book = chapteredBook($user);

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/rights", [
            'key' => 'registered_copyright',
            'value' => true,
        ])
        ->assertOk();

    expect($book->fresh()->rights()['registered_copyright'])->toBeTrue();
});

// --- Editions -----------------------------------------------------------
// An edition is a new book, so these run on a plan with room for one; the
// Free plan's limit is tested below.

it('builds a workbook with an exercise per heading', function () {
    $user = User::factory()->subscribed('author')->create();
    $book = chapteredBook($user);

    $response = $this->actingAs($user)
        ->postJson("/books/{$book->id}/editions", ['type' => 'workbook'])
        ->assertCreated();

    $edition = Book::find($response->json('edition.id'));

    expect($edition->edition_type)->toBe('workbook')
        ->and($edition->parent_book_id)->toBe($book->id)
        ->and($edition->sections()->count())->toBe(1)
        ->and($edition->sections()->first()->plainText())->toContain('Work through this');
});

it('builds a large-print edition with 14pt type and wide margins', function () {
    $user = User::factory()->subscribed('author')->create();
    $book = chapteredBook($user);

    $response = $this->actingAs($user)
        ->postJson("/books/{$book->id}/editions", ['type' => 'large_print'])
        ->assertCreated();

    $edition = Book::find($response->json('edition.id'));

    expect($edition->layout())
        ->toMatchArray(['size' => 14.0, 'margins' => 'Wide', 'trim' => '7 x 10 in']);
});

it('starts a translation in the chosen language with the text copied in', function () {
    $user = User::factory()->subscribed('author')->create();
    $book = chapteredBook($user);

    $response = $this->actingAs($user)
        ->postJson("/books/{$book->id}/editions", [
            'type' => 'translation',
            'language' => 'Spanish',
        ])
        ->assertCreated();

    $edition = Book::find($response->json('edition.id'));

    expect($edition->language)->toBe('Spanish')
        ->and($edition->sections()->first()->plainText())
        ->toContain('Prose that belongs in a book');
});

it('requires a language for a translation', function () {
    $user = User::factory()->subscribed('author')->create();
    $book = chapteredBook($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/editions", ['type' => 'translation'])
        ->assertStatus(422);
});

it('refuses to make an edition of an empty book', function () {
    $user = User::factory()->subscribed('author')->create();
    $book = $user->books()->create(['title' => 'Nothing Yet']);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/editions", ['type' => 'workbook'])
        ->assertStatus(422);
});

it('stops an editor from creating a new book on the owner\'s shelf', function () {
    // An edition is a whole new book. That is an owner's decision.
    $owner = User::factory()->subscribed('author')->create();
    $editor = User::factory()->create();
    $book = chapteredBook($owner);

    $book->members()->create([
        'user_id' => $editor->id,
        'email' => $editor->email,
        'role' => 'editor',
        'status' => 'active',
    ]);

    $this->actingAs($editor)
        ->postJson("/books/{$book->id}/editions", ['type' => 'workbook'])
        ->assertForbidden();
});

it('lists editions of a book and what a book was derived from', function () {
    $user = User::factory()->subscribed('author')->create();
    $book = chapteredBook($user);

    $editionId = $this->actingAs($user)
        ->postJson("/books/{$book->id}/editions", ['type' => 'workbook'])
        ->json('edition.id');

    expect($this->actingAs($user)->getJson("/books/{$book->id}/editions")->json('editions'))
        ->toHaveCount(1);

    expect($this->actingAs($user)->getJson("/books/{$editionId}/editions")->json('derived_from.id'))
        ->toBe($book->id);
});

it('holds an edition to the plan\'s book limit', function () {
    // The Free plan allows one book. Editions used to walk straight past
    // that: every workbook, translation and large-print copy was a new book.
    $user = User::factory()->create(['plan' => 'free']);
    $book = chapteredBook($user);

    foreach (['workbook', 'large_print'] as $type) {
        $this->actingAs($user)
            ->postJson("/books/{$book->id}/editions", ['type' => $type])
            ->assertForbidden();
    }

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/editions", ['type' => 'translation', 'language' => 'French'])
        ->assertForbidden();

    expect($user->books()->count())->toBe(1);
});

it('counts an edition toward the limit and frees a slot when one is archived', function () {
    $user = User::factory()->subscribed('author')->create();
    config(['billing.plans.author.books' => 2]);
    $book = chapteredBook($user);

    $editionId = $this->actingAs($user)
        ->postJson("/books/{$book->id}/editions", ['type' => 'workbook'])
        ->assertCreated()
        ->json('edition.id');

    $this->actingAs($user)->postJson("/books/{$book->id}/editions", ['type' => 'workbook'])->assertForbidden();

    // Archived books do not count, as the shelf has always said.
    $this->actingAs($user)->patch("/books/{$editionId}", ['status' => 'archived'])->assertRedirect();
    $second = $this->actingAs($user)->postJson("/books/{$book->id}/editions", ['type' => 'workbook'])->assertCreated();

    // Bringing the archived one back would be a third active book.
    $this->actingAs($user)->patch("/books/{$editionId}", ['status' => 'draft'])->assertForbidden();
    expect(Book::find($editionId)->status)->toBe('archived');

    expect($second->json('edition.id'))->not->toBe($editionId);
});

it('gives an edition the word count of the text it copied', function () {
    $user = User::factory()->subscribed('author')->create();
    $book = chapteredBook($user);

    foreach (['translation', 'large_print'] as $type) {
        $edition = Book::find($this->actingAs($user)
            ->postJson("/books/{$book->id}/editions", ['type' => $type, 'language' => 'French'])
            ->assertCreated()
            ->assertJsonPath('edition.word_count', 6)
            ->json('edition.id'));

        expect($edition->word_count)->toBe(6)
            ->and($edition->sections()->first()->word_count)->toBe(6);
    }

    $workbook = Book::find($this->actingAs($user)
        ->postJson("/books/{$book->id}/editions", ['type' => 'workbook'])
        ->json('edition.id'));

    expect($workbook->word_count)->toBeGreaterThan(0)
        ->and($workbook->word_count)->toBe((int) $workbook->sections()->sum('word_count'));
});

it('rebuilds an export whose file has gone', function () {
    /*
     * The 404 an author actually hit.
     *
     * Exports are written to the container's local disk, which is
     * ephemeral: a deploy replaces the container and takes every generated
     * file with it, while the export row stays in the database marked
     * ready. Clicking download on a file generated before the last deploy
     * returned a bare 404 with no explanation.
     *
     * No object storage is configured, so the download path now notices the
     * file is missing and regenerates it.
     */
    $user = User::factory()->create(['plan' => 'studio']);
    $book = chapteredBook($user);

    $export = app(Exporter::class)->generate($book, 'md', $user->id);

    expect($export->status)->toBe('ready');

    // Simulate the deploy that took the file away.
    Storage::disk('local')->delete($export->path);
    expect(Storage::disk('local')->exists($export->path))->toBeFalse();

    $response = $this->actingAs($user)
        ->get("/books/{$book->id}/exports/{$export->id}/download");

    $response->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('.md');
});

it('still refuses a download for somebody else\'s book', function () {
    // The rebuild path must not become a way around ownership.
    $owner = User::factory()->create(['plan' => 'studio']);
    $book = chapteredBook($owner);

    $export = app(Exporter::class)->generate($book, 'md', $owner->id);
    Storage::disk('local')->delete($export->path);

    $this->actingAs(User::factory()->create())
        ->get("/books/{$book->id}/exports/{$export->id}/download")
        ->assertForbidden();
});

it('does not offer a format as unbuilt when it builds', function () {
    /*
     * The stale placeholder.
     *
     * The bottom-row card said "Not built yet — needs the export renderers"
     * long after EPUB, DOCX, Markdown and HTML all generated. A placeholder
     * that outlives its feature talks an author out of trying something
     * that works.
     */
    $cards = file_get_contents(resource_path('js/Components/Studio/BottomCards.jsx'));

    $this->assertStringNotContainsString(
        'the export renderers',
        $cards,
        'The export card still claims the renderers do not exist.',
    );
});

// --- Files that must survive a deploy ------------------------------------

/*
 * Covers and exports were written to the container's disk, which every
 * deploy replaces. They now go to the files disk, the bucket in production.
 */
it('stores an uploaded cover on the files disk and serves it to the author', function () {
    Storage::fake('files');

    $user = User::factory()->create();
    $book = chapteredBook($user);

    $this->actingAs($user)
        ->post("/books/{$book->id}/cover/artwork", [
            'artwork' => UploadedFile::fake()->image('cover.jpg', 1600, 2560),
        ])
        ->assertOk();

    $path = $book->fresh()->cover['artwork'];
    Storage::disk('files')->assertExists($path);

    $url = $book->fresh()->coverFor()['url'];
    expect($url)->toContain("/books/{$book->id}/cover/image?v=");

    $this->actingAs($user)->get($url)->assertOk();

    // Somebody else's book's cover is not theirs to see.
    $this->actingAs(User::factory()->create())->get($url)->assertForbidden();
});

it('replaces the old cover file when a new one is uploaded', function () {
    Storage::fake('files');

    $user = User::factory()->create();
    $book = chapteredBook($user);

    $this->actingAs($user)->post("/books/{$book->id}/cover/artwork", [
        'artwork' => UploadedFile::fake()->image('first.jpg', 1600, 2560),
    ]);
    $first = $book->fresh()->cover['artwork'];
    $firstUrl = $book->fresh()->coverFor()['url'];

    $this->actingAs($user)->post("/books/{$book->id}/cover/artwork", [
        'artwork' => UploadedFile::fake()->image('second.jpg', 1600, 2560),
    ]);

    Storage::disk('files')->assertMissing($first);
    expect($book->fresh()->coverFor()['url'])->not->toBe($firstUrl);
});

it('writes exports to the files disk', function () {
    Storage::fake('files');

    $user = User::factory()->create();
    $book = chapteredBook($user);

    foreach (['epub', 'md'] as $format) {
        $export = app(Exporter::class)->generate($book, $format, $user->id);

        Storage::disk('files')->assertExists($export->path);
        expect($export->byte_size)->toBeGreaterThan(0);
    }
});

// --- The cover as a file ---------------------------------------------------

/*
 * Cover Studio drew the cover in the browser and nowhere else: there was no
 * file to download, none in the EPUB, nothing to give a store. The server
 * now renders it, and the preview is that rendering.
 */
it('renders the cover as a store-sized JPEG', function () {
    Storage::fake('files');

    $user = User::factory()->create();
    $book = chapteredBook($user);

    $response = $this->actingAs($user)->get($book->coverFor()['url'])->assertOk();
    $size = getimagesizefromstring($response->streamedContent());

    expect($size[0])->toBe(1600)
        ->and($size[1])->toBe(2560)
        ->and($size['mime'])->toBe('image/jpeg');
});

it('redraws the cover when the design changes, and keeps one copy', function () {
    Storage::fake('files');

    $user = User::factory()->create();
    $book = chapteredBook($user);
    $before = $book->coverFor()['url'];
    $this->actingAs($user)->get($before)->assertOk();

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/cover", ['layout' => 'band', 'title' => 'A New Title'])
        ->assertOk()
        ->assertJsonPath('cover.layout', 'band');

    $after = $book->fresh()->coverFor()['url'];
    expect($after)->not->toBe($before);

    $this->actingAs($user)->get($after)->assertOk();

    $fronts = collect(Storage::disk('files')->files("covers/book-{$book->id}"))
        ->filter(fn ($f) => str_starts_with(basename($f), 'front-'));
    expect($fronts)->toHaveCount(1);
});

it('refuses a layout that does not exist', function () {
    $user = User::factory()->create();
    $book = chapteredBook($user);

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/cover", ['layout' => 'collage'])
        ->assertStatus(422);
});

it('downloads the cover as a named file', function () {
    Storage::fake('files');

    $user = User::factory()->create();
    $book = chapteredBook($user, ['title' => 'Business Plans']);

    $this->actingAs($user)
        ->get("/books/{$book->id}/cover/download")
        ->assertOk()
        ->assertDownload('business-plans-cover.jpg');
});

it('puts the cover into the EPUB', function () {
    $user = User::factory()->create();
    $book = chapteredBook($user);

    $export = app(Exporter::class)->generate($book, 'epub', $user->id);

    $zip = new ZipArchive;
    $zip->open(Storage::disk('files')->path($export->path));
    $opf = $zip->getFromName('OEBPS/content.opf');
    $cover = $zip->getFromName('OEBPS/cover.jpg');
    $zip->close();

    expect($opf)->toContain('properties="cover-image"')
        ->and($opf)->toContain('<meta name="cover" content="cover-image"/>')
        ->and(getimagesizefromstring($cover)[0])->toBe(1600);
});

it('refuses artwork with more pixels than a cover can be drawn from', function () {
    // A small file can decode to hundreds of megabytes. Only the header is
    // needed to know: this one claims 8000 x 8000.
    Storage::fake('files');

    $header = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 8000, 8000)."\x08\x02\x00\x00\x00";
    $png = $header.pack('N', crc32(substr($header, 12))).str_repeat("\0", 64);

    $user = User::factory()->create();
    $book = chapteredBook($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/cover/artwork", [
            'artwork' => UploadedFile::fake()->createWithContent('huge.png', $png),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('artwork');

    expect($book->fresh()->cover['artwork'] ?? null)->toBeNull();
});
