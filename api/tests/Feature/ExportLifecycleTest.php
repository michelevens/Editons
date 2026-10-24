<?php

use App\Jobs\GenerateExport;
use App\Mail\Publishing\BookUnpublishedMail;
use App\Mail\Publishing\ExportFailedMail;
use App\Mail\Publishing\ExportReadyMail;
use App\Mail\Publishing\SubmittedToStoresMail;
use App\Models\Book;
use App\Models\BookExport;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\Exporter;
use App\Publishing\PrintBook;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * A PDF or EPUB from the click to the email (ADR-0018).
 *
 * The renders themselves are tested elsewhere. These are the paths around
 * them that used to go wrong: a second click queueing the same book twice,
 * rows a dead worker left "generating" for ever, a viewer queueing rebuilds,
 * a deleted book failing its job with a TypeError, and the emails.
 */
function lifecycleBook(User $owner, array $attributes = []): Book
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
            'content' => [['type' => 'text', 'text' => str_repeat('Prose that belongs in a book. ', 100)]],
        ]]],
    ]);

    $book->forceFill(['word_count' => 20_000])->save();

    return declared($book);
}

/** A row as the worker left it some seconds ago. */
function generatingSince(Book $book, string $format, int $seconds, ?int $userId = null): BookExport
{
    $export = $book->exports()->create(['format' => $format, 'status' => 'generating', 'user_id' => $userId ?? $book->user_id]);

    DB::table('book_exports')->where('id', $export->id)->update([
        'created_at' => now()->subSeconds($seconds),
        'updated_at' => now()->subSeconds($seconds),
    ]);

    return $export->fresh();
}

function viewerOf(Book $book): User
{
    $viewer = User::factory()->subscribed()->create();

    $book->members()->create([
        'user_id' => $viewer->id,
        'email' => $viewer->email,
        'role' => 'reviewer',
        'status' => 'active',
    ]);

    return $viewer;
}

beforeEach(function () {
    Storage::fake('files');
});

// ── One render at a time ────────────────────────────────────────────────

it('returns the PDF already rendering instead of queueing the book twice', function () {
    Queue::fake();
    $user = User::factory()->subscribed()->create();
    $book = lifecycleBook($user);

    $first = $this->actingAs($user)->postJson("/books/{$book->id}/exports", ['format' => 'pdf'])
        ->assertStatus(202)
        ->assertJsonPath('already', false);

    $second = $this->actingAs($user)->postJson("/books/{$book->id}/exports", ['format' => 'pdf'])
        ->assertStatus(202)
        ->assertJsonPath('already', true);

    expect($second->json('export.id'))->toBe($first->json('export.id'))
        ->and($book->exports()->where('status', 'generating')->count())->toBe(1);

    Queue::assertPushed(GenerateExport::class, 1);

    // Another format is its own render.
    $this->actingAs($user)->postJson("/books/{$book->id}/exports", ['format' => 'epub'])->assertStatus(202);
    Queue::assertPushed(GenerateExport::class, 2);
});

it('lets the database refuse a second render of the same format', function () {
    $user = User::factory()->create();
    $book = lifecycleBook($user);

    $book->exports()->create(['format' => 'pdf', 'status' => 'generating']);

    // In a savepoint, so the refusal does not abort the test's transaction.
    expect(fn () => DB::transaction(fn () => $book->exports()->create(['format' => 'pdf', 'status' => 'generating'])))
        ->toThrow(UniqueConstraintViolationException::class);

    // The small formats render in the request and are not limited.
    $book->exports()->create(['format' => 'md', 'status' => 'generating']);
    $book->exports()->create(['format' => 'md', 'status' => 'generating']);

    expect($book->exports()->where('format', 'md')->count())->toBe(2);
});

it('starts a new render when the running one is a dead worker\'s', function () {
    Queue::fake();
    $user = User::factory()->subscribed()->create();
    $book = lifecycleBook($user);
    $dead = generatingSince($book, 'pdf', BookExport::STALE_AFTER + 60);

    $response = $this->actingAs($user)->postJson("/books/{$book->id}/exports", ['format' => 'pdf'])
        ->assertStatus(202)
        ->assertJsonPath('already', false);

    expect($response->json('export.id'))->not->toBe($dead->id)
        ->and($dead->fresh()->status)->toBe('failed');

    Queue::assertPushed(GenerateExport::class, 1);
});

// ── Rows nothing will finish ────────────────────────────────────────────

it('fails generating rows past the job timeout and leaves recent ones alone', function () {
    Mail::fake();
    $user = User::factory()->create();
    $book = lifecycleBook($user);

    $dead = generatingSince($book, 'pdf', BookExport::STALE_AFTER + 60);
    $running = generatingSince($book, 'epub', 60);
    $ancient = generatingSince(lifecycleBook($user, ['title' => 'Old']), 'pdf', 3 * 86400);

    $this->artisan('exports:expire')->assertSuccessful();

    expect($dead->fresh()->status)->toBe('failed')
        ->and($running->fresh()->status)->toBe('generating')
        ->and($ancient->fresh()->status)->toBe('failed');

    // News for the render that died today; a row from days ago is history.
    Mail::assertQueued(ExportFailedMail::class, 1);
    Mail::assertQueued(ExportFailedMail::class, fn ($mail) => $mail->export->id === $dead->id && $mail->hasTo($user->email));
});

it('keeps a row whose job is still waiting in the queue', function () {
    // A long queue is not a dead worker: the job will run.
    config(['queue.default' => 'database']);
    $user = User::factory()->create();
    $book = lifecycleBook($user);
    $queued = generatingSince($book, 'pdf', BookExport::STALE_AFTER + 60);

    GenerateExport::dispatch($queued->id);

    expect(app(Exporter::class)->expireStale())->toBe(0)
        ->and($queued->fresh()->status)->toBe('generating')
        // Touched, so the screen counts its wait from now.
        ->and($queued->fresh()->updated_at->gt(now()->subMinute()))->toBeTrue();
});

it('settles a book\'s dead renders when the Publish screen loads, scheduler or not', function () {
    $user = User::factory()->create();
    $book = lifecycleBook($user);
    $dead = generatingSince($book, 'pdf', BookExport::STALE_AFTER + 60);
    $running = generatingSince($book, 'epub', 100);

    $response = $this->actingAs($user)->getJson("/books/{$book->id}/publishing")->assertOk();

    $rows = collect($response->json('exports'))->keyBy('id');

    expect($rows[$dead->id]['status'])->toBe('failed')
        ->and($rows[$dead->id]['wait_seconds'])->toBeNull()
        ->and($rows[$running->id]['status'])->toBe('generating')
        // What the screen polls against: it stops when this runs out.
        ->and($rows[$running->id]['wait_seconds'])->toBeGreaterThan(BookExport::STALE_AFTER - 200)
        ->and($rows[$running->id]['wait_seconds'])->toBeLessThanOrEqual(BookExport::STALE_AFTER - 100);
});

it('schedules the sweep', function () {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command)->implode("\n");

    expect($commands)->toContain('exports:expire')
        ->and($commands)->toContain('exports:expire --missing');
});

it('gives a long job longer than the worker timeout before another worker may take it', function () {
    // The worker runs with --timeout=1900 (docs/DECISIONS.md, ADR-0018).
    $job = new GenerateExport(1);

    expect(config('queue.connections.database.retry_after'))->toBeGreaterThan(1900)
        ->and(1900)->toBeGreaterThan($job->timeout)
        // The sweep waits until the worker has given up on the job.
        ->and(BookExport::STALE_AFTER)->toBeGreaterThan(1900);
});

// ── Files that have gone ────────────────────────────────────────────────

it('marks ready exports whose file has gone as expired', function () {
    $user = User::factory()->subscribed()->create();
    $book = lifecycleBook($user);

    $kept = app(Exporter::class)->generate($book, 'md', $user->id);
    $lost = app(Exporter::class)->generate($book, 'html', $user->id);
    Storage::disk('files')->delete($lost->path);

    $this->artisan('exports:expire --missing')->assertSuccessful();

    expect($kept->fresh()->status)->toBe('ready')
        ->and($lost->fresh()->status)->toBe('expired');

    // Expired says why there is nothing to download.
    $this->actingAs($user)
        ->get("/books/{$book->id}/exports/{$lost->id}/download")
        ->assertStatus(410);
});

it('does not let a view-only member queue rebuilds of a missing PDF', function () {
    Queue::fake();
    $owner = User::factory()->subscribed()->create();
    $book = lifecycleBook($owner);
    $viewer = viewerOf($book);

    $export = $book->exports()->create(['format' => 'pdf', 'status' => 'ready', 'path' => 'exports/gone.pdf', 'user_id' => $owner->id]);

    foreach (range(1, 3) as $click) {
        $this->actingAs($viewer)
            ->get("/books/{$book->id}/exports/{$export->id}/download")
            ->assertStatus(410);
    }

    Queue::assertNothingPushed();

    expect($book->exports()->where('status', 'generating')->count())->toBe(0)
        ->and($export->fresh()->status)->toBe('expired');
});

it('does not rebuild a missing file in a format the plan no longer includes', function () {
    $owner = User::factory()->create(); // the free plan: no Word
    $book = lifecycleBook($owner);
    $export = $book->exports()->create(['format' => 'docx', 'status' => 'ready', 'path' => 'exports/gone.docx', 'user_id' => $owner->id]);

    $this->actingAs($owner)
        ->get("/books/{$book->id}/exports/{$export->id}/download")
        ->assertStatus(410);

    expect($book->exports()->count())->toBe(1);
});

it('rebuilds a missing PDF for its author once, however often they click', function () {
    Queue::fake();
    $owner = User::factory()->subscribed()->create();
    $book = lifecycleBook($owner);
    $export = $book->exports()->create(['format' => 'pdf', 'status' => 'ready', 'path' => 'exports/gone.pdf', 'user_id' => $owner->id]);

    $this->actingAs($owner)->get("/books/{$book->id}/exports/{$export->id}/download")->assertStatus(503);
    $this->actingAs($owner)->get("/books/{$book->id}/exports/{$export->id}/download")->assertStatus(410);

    Queue::assertPushed(GenerateExport::class, 1);

    expect($book->exports()->where('status', 'generating')->count())->toBe(1);
});

// ── The job and the row ─────────────────────────────────────────────────

it('settles the row quietly when the book was deleted while the job waited', function () {
    Mail::fake();
    $user = User::factory()->create();
    $book = lifecycleBook($user);
    $export = app(Exporter::class)->start($book, 'pdf', $user->id);

    $book->delete(); // soft: the export row survives

    (new GenerateExport($export->id))->handle(app(Exporter::class));

    expect($export->fresh()->status)->toBe('failed');
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});

it('does not flip a row somebody else settled', function () {
    Mail::fake();
    $user = User::factory()->subscribed()->create();
    $book = lifecycleBook($user);
    $export = app(Exporter::class)->start($book, 'md', $user->id);

    $export->update(['status' => 'failed']);

    app(Exporter::class)->run($export);

    expect($export->fresh()->status)->toBe('failed')
        ->and($export->fresh()->path)->toBeNull()
        // The file it wrote is not left behind with nothing pointing at it.
        ->and(Storage::disk('files')->allFiles())->toBe([]);
});

it('builds zips in the app\'s own temporary folder and leaves nothing there', function () {
    $user = User::factory()->create();
    $book = lifecycleBook($user);
    $tmp = storage_path('app/tmp');
    $before = is_dir($tmp) ? glob($tmp.'/export-*.zip') : [];

    $epub = app(Exporter::class)->generate($book, 'epub', $user->id);

    expect($epub->status)->toBe('ready')
        ->and(glob($tmp.'/export-*.zip'))->toBe($before)
        ->and(file_get_contents(app_path('Publishing/Exporter.php')))->not->toContain('= tempnam(');
});

// ── Emails ──────────────────────────────────────────────────────────────

it('emails the author when a queued EPUB is ready, and not for the small formats', function () {
    Mail::fake();
    $user = User::factory()->subscribed()->create();
    $book = lifecycleBook($user);

    $export = app(Exporter::class)->start($book, 'epub', $user->id);
    (new GenerateExport($export->id))->handle(app(Exporter::class));

    Mail::assertQueued(ExportReadyMail::class, fn ($mail) => $mail->export->id === $export->id && $mail->hasTo($user->email));

    app(Exporter::class)->generate($book, 'md', $user->id);

    Mail::assertQueued(ExportReadyMail::class, 1);
});

it('emails the author when a queued render fails, once', function () {
    Mail::fake();
    $this->mock(PrintBook::class)->shouldReceive('pdf')->andThrow(new RuntimeException('An image could not be read.'));

    $user = User::factory()->create();
    $book = lifecycleBook($user);
    $export = app(Exporter::class)->start($book, 'pdf', $user->id);
    $job = new GenerateExport($export->id);

    expect(fn () => $job->handle(app(Exporter::class)))->toThrow(RuntimeException::class);

    // The queue then calls failed(); the row is already settled.
    $job->failed(new RuntimeException('An image could not be read.'));

    expect($export->fresh()->status)->toBe('failed');
    Mail::assertQueued(ExportFailedMail::class, 1);
    Mail::assertQueued(ExportFailedMail::class, fn ($mail) => $mail->reason === 'An image could not be read.');
});

it('emails the author when the worker stops a render that ran too long', function () {
    Mail::fake();
    $user = User::factory()->create();
    $export = app(Exporter::class)->start(lifecycleBook($user), 'pdf', $user->id);

    (new GenerateExport($export->id))->failed(new RuntimeException('Timed out'));
    (new GenerateExport($export->id))->failed(new RuntimeException('Timed out'));

    expect($export->fresh()->status)->toBe('failed');
    Mail::assertQueued(ExportFailedMail::class, 1);
});

it('emails the author the upload steps when the book is ready for the stores', function () {
    Mail::fake();
    $user = User::factory()->create();
    $book = lifecycleBook($user);
    $book->channels()->create(['channel' => 'amazon_kdp', 'format' => 'ebook']);
    $book->channels()->create(['channel' => 'ingram_print', 'format' => 'print']);

    $this->actingAs($user)->postJson("/books/{$book->id}/submit")->assertOk();

    Mail::assertQueued(SubmittedToStoresMail::class, function ($mail) use ($user) {
        $html = $mail->render();

        return $mail->hasTo($user->email)
            && $mail->channels === ['amazon_kdp', 'ingram_print']
            && str_contains($html, 'kdp.amazon.com')
            && ! str_contains($html, 'on its way to the stores');
    });

    // Nothing new on a second click, so no second email.
    $this->actingAs($user)->postJson("/books/{$book->id}/submit")->assertStatus(422);
    Mail::assertQueued(SubmittedToStoresMail::class, 1);
});

it('emails the author when they take a book off sale, and only then', function () {
    Mail::fake();
    $user = User::factory()->create();
    $book = lifecycleBook($user);

    $live = $book->channels()->create(['channel' => 'kobo', 'format' => 'ebook']);
    $live->forceFill(['status' => 'live', 'submitted_at' => now()->subWeek(), 'went_live_at' => now()->subDay()])->save();
    $never = $book->channels()->create(['channel' => 'apple_books', 'format' => 'ebook']);

    $this->actingAs($user)->postJson("/books/{$book->id}/channels/{$never->id}/unpublish")->assertStatus(422);
    Mail::assertNothingQueued();

    $this->actingAs($user)->postJson("/books/{$book->id}/channels/{$live->id}/unpublish")->assertOk();
    $this->actingAs($user)->postJson("/books/{$book->id}/channels/{$live->id}/unpublish")->assertStatus(422);

    Mail::assertQueued(BookUnpublishedMail::class, 1);
    Mail::assertQueued(BookUnpublishedMail::class, fn ($mail) => $mail->hasTo($user->email) && $mail->byAuthor);
});
