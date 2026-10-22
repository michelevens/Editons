<?php

use App\Import\MarkdownExporter;
use App\Import\MarkdownImporter;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\PortableBook;
use Illuminate\Http\UploadedFile;

/**
 * The .ecrivain file.
 *
 * The claim that justifies a native format: every other export loses
 * something, and this one comes back as the same book. Most of these test
 * exactly that — structure, nesting, journey state and all.
 *
 * The rest test that an import is treated as untrusted, following the shape
 * PlanDraft's .plandraft established: bounded sizes, no unexpected entries,
 * ownership from the session rather than the file.
 */
function portableBook(User $user, array $attributes = []): Book
{
    return $user->books()->create(array_merge([
        'title' => 'A Portable Book',
        'book_type' => 'nonfiction',
        'premise' => 'Something worth saying.',
        'audience' => 'People who need to hear it.',
        'target_words' => 60000,
        'journey_stage' => 'create',
    ], $attributes));
}

function portableSection(Book $book, string $type, string $title, int $position, ?ManuscriptSection $parent = null): ManuscriptSection
{
    $section = $book->sections()->create([
        'type' => $type,
        'title' => $title,
        'position' => $position,
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "Prose for {$title}."]]],
        ]],
    ]);

    if ($parent) {
        $section->forceFill(['parent_id' => $parent->id])->save();
    }

    $section->forceFill(['word_count' => ManuscriptSection::countWords($section->content)])->save();

    return $section;
}

/** Export a book and import it straight back, returning the new one. */
function roundTrip(Book $book, ?User $into = null): Book
{
    $portable = app(PortableBook::class);
    [$path] = $portable->export($book);

    try {
        return $portable->import($path, ($into ?? $book->user)->id);
    } finally {
        @unlink($path);
    }
}

// --- Round-tripping ------------------------------------------------------

it('comes back as the same book', function () {
    $user = User::factory()->create();
    $book = portableBook($user);

    $part = portableSection($book, 'part', 'Part One', 10);
    portableSection($book, 'chapter', 'First', 20, $part);
    portableSection($book, 'chapter', 'Second', 30, $part);

    $book->refresh()->recalculateWordCount();
    $copy = roundTrip($book->fresh());

    expect($copy->title)->toBe('A Portable Book')
        ->and($copy->sections()->count())->toBe(3)
        ->and($copy->word_count)->toBe($book->fresh()->word_count);
});

it('keeps the binder tree', function () {
    // The thing Markdown export loses. A flat list of the same chapters is
    // not the same book.
    $user = User::factory()->create();
    $book = portableBook($user);

    $one = portableSection($book, 'part', 'Part One', 10);
    portableSection($book, 'chapter', 'A', 20, $one);
    portableSection($book, 'chapter', 'B', 30, $one);

    $two = portableSection($book, 'part', 'Part Two', 40);
    portableSection($book, 'chapter', 'C', 50, $two);

    $copy = roundTrip($book->fresh());

    $parts = $copy->sections()->where('type', 'part')->orderBy('position')->get();

    expect($parts)->toHaveCount(2);

    foreach ($parts as $part) {
        expect($copy->sections()->where('parent_id', $part->id)->count())
            ->toBeGreaterThan(0, "[{$part->title}] came back holding nothing.");
    }

    expect($copy->sections()->whereNotNull('parent_id')->count())->toBe(3);
});

it('keeps the journey state and the idea', function () {
    // What the reading formats cannot carry: this is why the format exists.
    $user = User::factory()->create();
    $book = portableBook($user, [
        'promise' => 'A thing the reader gets.',
        'assistance' => 'coach',
        'started_with' => 'outline',
    ]);

    portableSection($book, 'chapter', 'One', 10);

    $copy = roundTrip($book->fresh());

    expect($copy->premise)->toBe($book->premise)
        ->and($copy->audience)->toBe($book->audience)
        ->and($copy->promise)->toBe($book->promise)
        ->and($copy->book_type)->toBe($book->book_type)
        ->and($copy->target_words)->toBe($book->target_words)
        ->and($copy->assistance)->toBe('coach')
        ->and($copy->journey_stage)->toBe('create');
});

it('keeps the prose, not just the titles', function () {
    $user = User::factory()->create();
    $book = portableBook($user);

    portableSection($book, 'chapter', 'Has Words', 10);

    $copy = roundTrip($book->fresh());
    $section = $copy->sections()->first();

    expect($section->plainText())->toContain('Prose for Has Words')
        ->and($section->word_count)->toBeGreaterThan(0);
});

it('survives a second round trip unchanged', function () {
    // A format that degrades each time it is opened is not portable.
    $user = User::factory()->create();
    $book = portableBook($user);

    $part = portableSection($book, 'part', 'Part One', 10);
    portableSection($book, 'chapter', 'A', 20, $part);
    portableSection($book, 'chapter', 'B', 30, $part);

    $book->refresh()->recalculateWordCount();

    $once = roundTrip($book->fresh());
    $twice = roundTrip($once->fresh());

    expect($twice->word_count)->toBe($book->fresh()->word_count)
        ->and($twice->sections()->count())->toBe(3)
        ->and($twice->sections()->whereNotNull('parent_id')->count())->toBe(2);
});

it('moves a book between accounts', function () {
    // The reason somebody exports: a co-author, another workspace, a backup
    // restored somewhere else.
    $author = User::factory()->create();
    $other = User::factory()->create();

    $book = portableBook($author);
    portableSection($book, 'chapter', 'One', 10);

    $copy = roundTrip($book->fresh(), $other);

    expect($copy->user_id)->toBe($other->id)
        ->and($copy->id)->not->toBe($book->id)
        ->and($copy->title)->toBe($book->title);
});

// --- The file ------------------------------------------------------------

it('writes a manifest describing what is inside', function () {
    $user = User::factory()->create();
    $book = portableBook($user);
    portableSection($book, 'chapter', 'One', 10);

    [$path] = app(PortableBook::class)->export($book->fresh());

    $zip = new ZipArchive;
    $zip->open($path);
    $manifest = json_decode($zip->getFromName('manifest.json'), true);
    $names = array_map(fn ($i) => $zip->statIndex($i)['name'], range(0, $zip->numFiles - 1));
    $zip->close();
    @unlink($path);

    expect($names)->toEqualCanonicalizing(['manifest.json', 'book.json'])
        ->and($manifest['format'])->toBe('ecrivain')
        ->and($manifest['version'])->toBe(1)
        ->and($manifest['title'])->toBe('A Portable Book')
        ->and($manifest['sections'])->toBe(1)
        ->and($manifest['signature'])->not->toBeEmpty();
});

it('verifies a file it wrote itself', function () {
    $user = User::factory()->create();
    $book = portableBook($user);
    portableSection($book, 'chapter', 'One', 10);

    [$path] = app(PortableBook::class)->export($book->fresh());
    $opened = app(PortableBook::class)->inspect($path);
    @unlink($path);

    expect($opened['verified'])->toBeTrue();
});

it('notices a file that has been edited', function () {
    // Not a security boundary — an unverified file still imports — but an
    // altered manuscript should not claim to be untouched.
    $user = User::factory()->create();
    $book = portableBook($user);
    portableSection($book, 'chapter', 'One', 10);

    [$path] = app(PortableBook::class)->export($book->fresh());

    $zip = new ZipArchive;
    $zip->open($path);
    $data = json_decode($zip->getFromName('book.json'), true);
    $data['book']['title'] = 'Somebody Else\'s Book';
    $zip->addFromString('book.json', json_encode($data));
    $zip->close();

    $opened = app(PortableBook::class)->inspect($path);
    @unlink($path);

    expect($opened['verified'])->toBeFalse();
});

// --- Untrusted input -----------------------------------------------------

it('refuses something that is not a book file', function () {
    $path = tempnam(sys_get_temp_dir(), 'notabook');
    file_put_contents($path, 'this is not a zip');

    expect(fn () => app(PortableBook::class)->inspect($path))
        ->toThrow(RuntimeException::class);

    @unlink($path);
});

it('refuses a zip carrying anything unexpected', function () {
    // The entry allowlist: a book file holds two files and nothing else.
    $path = tempnam(sys_get_temp_dir(), 'ecr').'.ecrivain';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode(['format' => 'ecrivain', 'version' => 1]));
    $zip->addFromString('book.json', json_encode(['book' => [], 'sections' => []]));
    $zip->addFromString('../escape.sh', 'rm -rf /');
    $zip->close();

    expect(fn () => app(PortableBook::class)->inspect($path))
        ->toThrow(RuntimeException::class);

    @unlink($path);
});

it('refuses a file from a newer version', function () {
    $path = tempnam(sys_get_temp_dir(), 'ecr').'.ecrivain';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode(['format' => 'ecrivain', 'version' => 99]));
    $zip->addFromString('book.json', json_encode(['book' => [], 'sections' => []]));
    $zip->close();

    expect(fn () => app(PortableBook::class)->inspect($path))
        ->toThrow(RuntimeException::class, 'That book was made by a newer version of Ecrivain.');

    @unlink($path);
});

it('takes ownership from the account, never from the file', function () {
    $author = User::factory()->create();
    $attacker = User::factory()->create();

    $book = portableBook($author);
    portableSection($book, 'chapter', 'One', 10);

    [$path] = app(PortableBook::class)->export($book->fresh());

    // Put somebody else's id in the file and import it as the attacker.
    $zip = new ZipArchive;
    $zip->open($path);
    $data = json_decode($zip->getFromName('book.json'), true);
    $data['book']['user_id'] = $author->id;
    $zip->addFromString('book.json', json_encode($data));
    $zip->close();

    $copy = app(PortableBook::class)->import($path, $attacker->id);
    @unlink($path);

    expect($copy->user_id)->toBe($attacker->id);
});

it('drops a parent reference pointing outside the file', function () {
    $user = User::factory()->create();
    $path = tempnam(sys_get_temp_dir(), 'ecr').'.ecrivain';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode(['format' => 'ecrivain', 'version' => 1]));
    $zip->addFromString('book.json', json_encode([
        'book' => ['title' => 'Broken Tree'],
        'sections' => [
            ['type' => 'chapter', 'title' => 'Orphan', 'position' => 10, 'parent' => 99],
            ['type' => 'chapter', 'title' => 'Itself', 'position' => 20, 'parent' => 1],
        ],
    ]));
    $zip->close();

    $book = app(PortableBook::class)->import($path, $user->id);
    @unlink($path);

    expect($book->sections()->count())->toBe(2)
        ->and($book->sections()->whereNotNull('parent_id')->count())->toBe(0);
});

it('gives an untitled book a title', function () {
    $user = User::factory()->create();
    $path = tempnam(sys_get_temp_dir(), 'ecr').'.ecrivain';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode(['format' => 'ecrivain', 'version' => 1]));
    $zip->addFromString('book.json', json_encode(['book' => ['title' => '   '], 'sections' => []]));
    $zip->close();

    $book = app(PortableBook::class)->import($path, $user->id);
    @unlink($path);

    expect($book->title)->toBe('Untitled book');
});

it('holds layout, rights and listing copy to the rules the screens apply', function () {
    // From the audit: a crafted file stored layout.font = "serif</style>…"
    // and an unbounded size, which the Design screen would have refused.
    $user = User::factory()->create();
    $path = tempnam(sys_get_temp_dir(), 'ecr').'.ecrivain';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode(['format' => 'ecrivain', 'version' => 1]));
    $zip->addFromString('book.json', json_encode(['book' => [
        'title' => 'Crafted',
        'journey_stage' => 'nowhere',
        'language' => null,
        'assistance' => 'collaborative',
        'target_words' => 'lots',
        'layout' => [
            'font' => 'serif</style><script>alert(11)</script>',
            'size' => '11;}</style><script>alert(12)</script>',
            'leading' => 1.4,
            'trim' => '5 x 8 in',
            'dropcap' => true,
            'injected' => 'anything',
        ],
        'rights' => ['registered_copyright' => true, 'permissions_cleared' => '<b>yes</b>', 'extra' => true],
        'meta' => ['description' => 'Fine copy.', 'keywords' => str_repeat('k', 600), 'html' => '<script>'],
    ], 'sections' => []]));
    $zip->close();

    $book = app(PortableBook::class)->import($path, $user->id);
    @unlink($path);

    expect($book->layout)->toEqual(['trim' => '5 x 8 in', 'leading' => 1.4, 'dropcap' => true])
        ->and($book->layout()['font'])->toBe('serif')
        ->and($book->layout()['size'])->toBe(11.0)
        ->and($book->rights)->toBe(['registered_copyright' => true])
        ->and($book->meta)->toBe(['description' => 'Fine copy.'])
        ->and($book->assistance)->toBe('collaborative')
        ->and($book->journey_stage)->toBe('discover')
        ->and($book->language)->toBe('English (US)')
        ->and($book->target_words)->not->toBe('lots');
});

it('keeps every layout setting a real export carries', function () {
    $user = User::factory()->create();
    $layout = [
        'trim' => '5.5 x 8.5 in', 'margins' => 'Wide', 'font' => 'sans',
        'size' => 12.5, 'leading' => 1.6, 'dropcap' => true, 'headers' => false,
    ];
    $book = portableBook($user, [
        'layout' => $layout,
        'rights' => ['registered_copyright' => true, 'permissions_cleared' => false, 'image_licenses' => true],
        'meta' => ['description' => 'Listing copy.', 'category' => 'Memoir', 'keywords' => 'one, two'],
    ]);

    $copy = roundTrip($book->fresh());

    expect($copy->layout)->toEqual($layout)
        ->and($copy->rights)->toEqual($book->rights)
        ->and($copy->meta)->toEqual($book->meta);
});

// --- Over HTTP -----------------------------------------------------------

it('downloads as a file', function () {
    $user = User::factory()->create();
    $book = portableBook($user);
    portableSection($book, 'chapter', 'One', 10);

    $response = $this->actingAs($user)->get("/books/{$book->id}/export.ecrivain");

    $response->assertOk();

    expect($response->headers->get('content-disposition'))
        ->toContain('a-portable-book.ecrivain');
});

it('will not export a stranger\'s book', function () {
    $owner = User::factory()->create();
    $book = portableBook($owner);

    $this->actingAs(User::factory()->create())
        ->get("/books/{$book->id}/export.ecrivain")
        ->assertForbidden();
});

it('still enforces the plan limit on import', function () {
    // A file is not a way around a limit.
    $author = User::factory()->create();
    $book = portableBook($author);
    portableSection($book, 'chapter', 'One', 10);

    [$path] = app(PortableBook::class)->export($book->fresh());

    $free = User::factory()->create(['plan' => 'free']);
    $free->books()->create(['title' => 'The One The Free Plan Allows']);

    $this->actingAs($free)
        ->post('/books/import', [
            'file' => new UploadedFile($path, 'book.ecrivain', null, null, true),
        ])
        ->assertForbidden();

    expect($free->books()->count())->toBe(1);

    @unlink($path);
});

it('requires signing in to import', function () {
    $this->post('/books/import')->assertRedirect('/login');
});

it('keeps what Markdown export loses', function () {
    /*
     * The measurement that justifies a native format.
     *
     * Markdown is a fine reading format and a lossy manuscript format: it
     * splits on every heading, so a chapter with h2 sections becomes several
     * sections, and the binder tree disappears entirely. Measured on a real
     * book, 29 sections became 143 and all five parts were lost.
     *
     * This asserts the difference rather than describing it, so a future
     * change to either path has to keep the native format the lossless one.
     */
    $user = User::factory()->create();
    $book = portableBook($user);

    $part = portableSection($book, 'part', 'Part One', 10);

    $chapter = $book->sections()->create([
        'type' => 'chapter',
        'title' => 'A Chapter With Sections',
        'position' => 20,
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'A Chapter With Sections']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Opening prose for the chapter.']]],
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => '1.1 A Section']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'More prose beneath the section heading.']]],
        ]],
    ]);

    $chapter->forceFill([
        'parent_id' => $part->id,
        'word_count' => ManuscriptSection::countWords($chapter->content),
    ])->save();

    $book->refresh()->recalculateWordCount();
    $original = $book->fresh();

    // Native: identical.
    $native = roundTrip($original);

    expect($native->sections()->count())->toBe($original->sections()->count())
        ->and($native->sections()->where('type', 'part')->count())->toBe(1)
        ->and($native->sections()->whereNotNull('parent_id')->count())->toBe(1)
        ->and($native->word_count)->toBe($original->word_count);

    // Markdown: structurally lossy. Asserted as a known limitation, not a
    // bug — it is why the native format exists.
    $markdown = app(MarkdownExporter::class)->book($original);

    $viaMarkdown = $user->books()->create(['title' => 'Via Markdown']);
    $viaMarkdown->sections()->delete();
    app(MarkdownImporter::class)->import($viaMarkdown, $markdown, $user->id);

    expect($viaMarkdown->fresh()->sections()->where('type', 'part')->count())
        ->toBe(0, 'Markdown import has started keeping parts — update this test and the native format docs.');
});

it('declares the extensions it cannot run without', function () {
    /*
     * The bug this guards, found only in production.
     *
     * EPUB, DOCX and the .ecrivain format are all ZIP archives, and the
     * deployed container did not have ext-zip. Every one of those exports
     * returned "Class ZipArchive not found" while the whole local suite
     * passed, because this machine's PHP has the extension and the
     * builder had no reason to install it.
     *
     * composer.json now requires it, which is what makes the builder
     * install it. This asserts the declaration stays, since removing it
     * breaks production and nothing else.
     */
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);

    foreach (['ext-zip'] as $extension) {
        // assertArrayHasKey, not toHaveKey: Pest's second argument is an
        // expected VALUE, so the message would be compared against '*'.
        $this->assertArrayHasKey(
            $extension,
            $composer['require'],
            "composer.json does not require {$extension}, so a deployment may not have it.",
        );
    }

    // And it is genuinely needed: these formats cannot be built without it.
    expect(class_exists(ZipArchive::class))->toBeTrue();
});
