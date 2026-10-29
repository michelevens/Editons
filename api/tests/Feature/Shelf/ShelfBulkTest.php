<?php

use App\Jobs\GenerateExport;
use App\Models\AdminAuditLog;
use App\Models\Book;
use App\Models\HouseStyle;
use App\Models\Product;
use App\Models\User;
use App\Platform\House\HouseAccounts;
use App\Shelf\ShelfBulk;
use Illuminate\Support\Facades\Bus;

/*
 * Bulk actions on the shelf (ADR-0045 — Shelf views and bulk actions): each
 * book checked through the BookPolicy on its own, skipped with a reason when
 * the person cannot change it, never named when they cannot see it; house
 * books changed by staff are audited with the reason given.
 */

function bulkBook(User $owner, array $attributes = []): Book
{
    return Book::factory()->for($owner)->create(array_merge(['status' => 'draft', 'series_name' => null, 'subtitle' => null], $attributes));
}

function bulk($test, User $user, string $action, array $books, array $params = [])
{
    return $test->actingAs($user)->postJson('/shelf/bulk', ['action' => $action, 'books' => array_map(fn ($b) => $b instanceof Book ? $b->id : $b, $books)] + $params);
}

function rowFor($response, Book|int $book): array
{
    $id = $book instanceof Book ? $book->id : $book;

    return collect($response->json('rows'))->firstWhere('id', $id);
}

beforeEach(function () {
    setPlan('free', ['books' => null]);
    $this->author = User::factory()->create();
});

it('archives the selected books, and skips with a reason those the person cannot archive', function () {
    $a = bulkBook($this->author, ['title' => 'A']);
    $done = bulkBook($this->author, ['title' => 'Done', 'status' => 'archived']);
    $theirs = bulkBook(User::factory()->create(), ['title' => 'Secret title']);
    $shared = bulkBook(User::factory()->create(), ['title' => 'Shared']);
    $shared->members()->create(['user_id' => $this->author->id, 'email' => $this->author->email, 'role' => 'editor', 'status' => 'active']);

    $response = bulk($this, $this->author, 'archive', [$a, $done, $theirs, $shared, 999999])->assertOk()
        ->assertJson(['action' => 'archive', 'done' => 1, 'skipped' => 4]);

    expect($a->fresh()->status)->toBe('archived')
        ->and($theirs->fresh()->status)->toBe('draft')
        ->and($shared->fresh()->status)->toBe('draft')
        ->and(rowFor($response, $done)['message'])->toBe('Already archived.')
        // An editor may write in the book, but archiving is the owner's.
        ->and(rowFor($response, $shared)['message'])->toContain('owner')
        // Another account's book is not found — and not named.
        ->and(rowFor($response, $theirs))->toBe(['id' => $theirs->id, 'title' => null, 'ok' => false, 'message' => 'Not found.'])
        ->and(rowFor($response, 999999)['message'])->toBe('Not found.')
        // The rows come back in the order selected.
        ->and(array_column($response->json('rows'), 'id'))->toBe([$a->id, $done->id, $theirs->id, $shared->id, 999999])
        // An author's own book is not a console action: no admin audit row.
        ->and(AdminAuditLog::count())->toBe(0);
});

it('unarchives back to Drafts while the plan has room', function () {
    setPlan('free', ['books' => 2]);
    bulkBook($this->author, ['title' => 'Active']);
    $one = bulkBook($this->author, ['title' => 'One', 'status' => 'archived']);
    $two = bulkBook($this->author, ['title' => 'Two', 'status' => 'archived']);
    $live = bulkBook($this->author, ['title' => 'Live', 'status' => 'published']);

    // Two active already ("Active", "Live"): no room for either.
    $live->forceFill(['status' => 'archived'])->save();
    $response = bulk($this, $this->author, 'unarchive', [$one, $two])->assertOk()->assertJson(['done' => 1, 'skipped' => 1]);

    expect($one->fresh()->status)->toBe('draft')
        ->and($two->fresh()->status)->toBe('archived')
        ->and(rowFor($response, $two)['message'])->toContain('plan');
});

it('sets one series and numbers the books in the order they were selected', function () {
    [$c, $a, $b] = [bulkBook($this->author, ['title' => 'C']), bulkBook($this->author, ['title' => 'A']), bulkBook($this->author, ['title' => 'B'])];
    $reviewed = bulkBook(User::factory()->create(), ['title' => 'Reviewed']);
    $reviewed->members()->create(['user_id' => $this->author->id, 'email' => $this->author->email, 'role' => 'reviewer', 'status' => 'active']);

    $response = bulk($this, $this->author, 'series', [$b, $reviewed, $c, $a], ['series_name' => 'Écrivain Prépa'])->assertOk()
        ->assertJson(['done' => 3, 'skipped' => 1]);

    expect([$b->fresh()->series_number, $c->fresh()->series_number, $a->fresh()->series_number])->toBe([1, 2, 3])
        ->and($a->fresh()->series_name)->toBe('Écrivain Prépa')
        ->and($reviewed->fresh()->series_name)->toBeNull()
        ->and(rowFor($response, $reviewed)['message'])->toContain('not change')
        // The search text follows the series.
        ->and($a->fresh()->search_text)->toContain('ecrivain prepa');

    bulk($this, $this->author, 'series', [$a, $b], ['series_name' => 'Second', 'start' => 5])->assertOk();
    expect([$a->fresh()->series_number, $b->fresh()->series_number])->toBe([5, 6]);

    // An empty name takes them out of any series.
    bulk($this, $this->author, 'series', [$a], ['series_name' => ''])->assertOk();
    expect($a->fresh()->only('series_name', 'series_number'))->toBe(['series_name' => null, 'series_number' => null]);
});

it('applies a house style through HouseStyles, per book', function () {
    $a = bulkBook($this->author, ['title' => 'A', 'layout' => ['font' => 'sans']]);
    $b = bulkBook($this->author, ['title' => 'B']);
    $theirs = bulkBook(User::factory()->create(), ['title' => 'Theirs']);
    $style = new HouseStyle(['name' => 'Classic', 'design' => ['font' => 'serif']]);
    $style->forceFill(['user_id' => $this->author->id, 'version' => 1])->save();

    $response = bulk($this, $this->author, 'house_style', [$a, $b, $theirs], ['house_style_id' => $style->id, 'follow' => true])->assertOk()
        ->assertJson(['done' => 2, 'skipped' => 1]);

    expect($a->fresh()->house_style_id)->toBe($style->id)
        ->and($b->fresh()->house_style_id)->toBe($style->id)
        ->and($theirs->fresh()->house_style_id)->toBeNull()
        ->and(rowFor($response, $theirs)['title'])->toBeNull();

    // Another account's style is refused for this person's books.
    $foreign = new HouseStyle(['name' => 'Foreign', 'design' => []]);
    $foreign->forceFill(['user_id' => User::factory()->create()->id, 'version' => 1])->save();
    bulk($this, $this->author, 'house_style', [$a], ['house_style_id' => $foreign->id])->assertOk()->assertJson(['done' => 0, 'skipped' => 1]);
});

it('regenerates only the stale Full-book files of the chosen formats, through the Exporter', function () {
    Bus::fake();
    $stale = pfBook($this->author, ['title' => 'Stale']);
    pfFile($stale, 'epub');
    $stale->exports()->update(['created_at' => now()->subDay()]);
    $stale->sections()->first()->touch();

    $fresh = pfBook($this->author, ['title' => 'Fresh']);
    pfFile($fresh, 'epub');

    $never = pfBook($this->author, ['title' => 'Never']);

    $response = bulk($this, $this->author, 'regenerate', [$stale, $fresh, $never], ['formats' => ['epub']])->assertOk()
        ->assertJson(['done' => 1, 'skipped' => 2]);

    Bus::assertDispatched(GenerateExport::class, 1);
    expect($stale->exports()->where('status', 'generating')->count())->toBe(1)
        ->and($fresh->exports()->where('status', 'generating')->count())->toBe(0)
        ->and(rowFor($response, $fresh)['message'])->toContain('Up to date')
        ->and(rowFor($response, $never)['message'])->toContain('Never made')
        // The progress of what was queued, from the same freshness check.
        ->and($this->actingAs($this->author)->getJson('/shelf/progress?'.http_build_query(['books' => [$stale->id, $fresh->id], 'formats' => ['epub']]))
            ->assertOk()->json("books.{$stale->id}.epub.rendering"))->toBeTrue();

    // "Also make files never made".
    bulk($this, $this->author, 'regenerate', [$never], ['formats' => ['epub'], 'missing' => true])->assertOk()->assertJson(['done' => 1]);
    expect($never->exports()->where('status', 'generating')->count())->toBe(1);

    // A cover wrap needs a print service.
    expect(rowFor(bulk($this, $this->author, 'regenerate', [$fresh], ['formats' => ['cover_wrap'], 'missing' => true]), $fresh)['message'])->toContain('No print service');

    // A format is required, and the number of books is bounded.
    bulk($this, $this->author, 'regenerate', [$fresh])->assertUnprocessable();
    expect(fn () => app(ShelfBulk::class)->run('regenerate', range(1, ShelfBulk::MAX_REGENERATE + 1), ['formats' => ['epub']], $this->author, null))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('respects the plan: a format it does not include is refused, not queued', function () {
    Bus::fake();
    setPlan('free', ['books' => null, 'formats' => ['pdf']]);
    $book = pfBook($this->author);

    $response = bulk($this, $this->author, 'regenerate', [$book], ['formats' => ['epub'], 'missing' => true])->assertOk()->assertJson(['done' => 0]);

    Bus::assertNotDispatched(GenerateExport::class);
    expect(rowFor($response, $book)['files'][0]['result'])->toBe('refused');
});

it('prepares for publishing from the shelf, skipping the books the person cannot change', function () {
    Bus::fake();
    $mine = pfBook($this->author);
    $theirs = pfBook(User::factory()->create());

    $response = bulk($this, $this->author, 'prepare', [$mine, $theirs])->assertOk()->assertJson(['done' => 1, 'skipped' => 1]);

    expect(rowFor($response, $mine)['status'])->toBeIn(['green', 'amber', 'red'])
        ->and($response->json('links.google_play'))->toContain('books')
        ->and($response->json('links.kdp_checklist'))->not->toContain((string) $theirs->id.'&');
});

it('exports a metadata CSV, with formulas defused', function () {
    $book = pfBook($this->author, ['title' => '=HYPERLINK("http://evil","x")', 'subtitle' => '+1 trick', 'series_name' => '@series', 'series_number' => 3, 'language' => 'French']);
    pfIsbn($book, '9780306406157', 'ebook');
    $product = new Product(['price_cents' => 1299, 'currency' => 'usd', 'formats' => ['epub'], 'license' => 'personal']);
    $product->forceFill(['user_id' => $this->author->id, 'book_id' => $book->id])->save();
    $theirs = pfBook(User::factory()->create(), ['title' => 'Not yours']);

    $response = $this->actingAs($this->author)->get('/shelf/metadata.csv?'.http_build_query(['books' => [$book->id, $theirs->id]]))->assertOk();
    $body = $response->streamedContent();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($body)->not->toContain('Not yours');

    $rows = array_map('str_getcsv', array_filter(explode("\n", substr($body, 3))));
    $row = array_combine($rows[0], $rows[1]);

    expect($rows)->toHaveCount(2)
        ->and($row['title'])->toBe("'=HYPERLINK(\"http://evil\",\"x\")")
        ->and($row['subtitle'])->toBe("'+1 trick")
        ->and($row['series'])->toBe("'@series")
        ->and($row['series_number'])->toBe('3')
        ->and($row['language'])->toBe('French')
        ->and($row['isbn_ebook'])->toBe('9780306406157')
        ->and($row['price'])->toBe('12.99')
        ->and($row['currency'])->toBe('USD')
        ->and((int) $row['pages'])->toBeGreaterThan(0)
        ->and($row['pages_source'])->toBe('estimate')
        ->and($row['status'])->toBe('draft');

    expect(ShelfBulk::csvCell('-2+3'))->toBe("'-2+3")
        ->and(ShelfBulk::csvCell("\tcmd"))->toBe("'\tcmd")
        ->and(ShelfBulk::csvCell(-5))->toBe('-5')
        ->and(ShelfBulk::csvCell('Plain'))->toBe('Plain');
});

describe('house books', function () {
    beforeEach(function () {
        $this->house = app(HouseAccounts::class)->create('ecrivain-prepa', 'Écrivain Prépa', 'Test imprint');
        $this->owner = platformAdmin();
    });

    it('lets the platform owner act on house books, with a reason, each change audited', function () {
        $a = bulkBook($this->house, ['title' => 'Prépa 1']);
        $b = bulkBook($this->house, ['title' => 'Prépa 2']);

        bulk($this, $this->owner, 'series', [$b, $a], ['series_name' => 'Écrivain Prépa'])->assertUnprocessable()->assertJsonValidationErrors('reason');

        bulk($this, $this->owner, 'series', [$b, $a], ['series_name' => 'Écrivain Prépa', 'reason' => 'Number the series'])->assertOk()->assertJson(['done' => 2]);
        bulk($this, $this->owner, 'archive', [$a], ['reason' => 'Withdrawn edition'])->assertOk()->assertJson(['done' => 1]);

        $series = AdminAuditLog::where('action', 'house.series_set')->orderBy('id')->get();
        expect($series)->toHaveCount(2)
            ->and($series[0]->subject_id)->toBe($b->id)
            ->and($series[0]->after)->toBe(['series_name' => 'Écrivain Prépa', 'series_number' => 1])
            ->and($series[0]->reason)->toBe('Number the series')
            ->and($series[0]->actor_id)->toBe($this->owner->id)
            ->and(AdminAuditLog::where('action', 'house.archived')->sole()->before)->toBe(['status' => 'draft']);
    });

    it('audits a house style applied to house books', function () {
        $a = bulkBook($this->house, ['title' => 'Prépa 1']);
        $style = new HouseStyle(['name' => 'Prépa', 'design' => ['font' => 'sans']]);
        $style->forceFill(['user_id' => $this->house->id, 'version' => 1])->save();

        bulk($this, $this->owner, 'house_style', [$a], ['house_style_id' => $style->id, 'reason' => 'One look'])->assertOk()->assertJson(['done' => 1]);

        expect($a->fresh()->house_style_id)->toBe($style->id)
            ->and(AdminAuditLog::where('action', 'house.house_style_applied')->sole()->reason)->toBe('One look');
    });

    it('audits regenerated house exports and the metadata CSV', function () {
        Bus::fake();
        $a = pfBook($this->house);

        bulk($this, $this->owner, 'regenerate', [$a], ['formats' => ['epub'], 'missing' => true, 'reason' => 'Fresh files'])->assertOk()->assertJson(['done' => 1]);
        expect(AdminAuditLog::where('action', 'house.exports_regenerated')->sole()->after['files'])->toBe(['epub=queued']);

        $this->actingAs($this->owner)->get('/shelf/metadata.csv?'.http_build_query(['books' => [$a->id]]))->assertOk()->streamedContent();
        expect(AdminAuditLog::where('action', 'house.metadata_exported')->sole()->after)->toBe(['books' => [$a->id]]);
    });

    it('lets support read house books but change none, and gives an author nothing', function () {
        $a = bulkBook($this->house, ['title' => 'Prépa 1']);

        $support = bulk($this, platformAdmin('support'), 'archive', [$a], ['reason' => 'Trying'])->assertOk()->assertJson(['done' => 0, 'skipped' => 1]);
        expect(rowFor($support, $a)['title'])->toBe('Prépa 1');

        $author = bulk($this, $this->author, 'archive', [$a], ['reason' => 'Trying'])->assertOk()->assertJson(['done' => 0]);
        expect(rowFor($author, $a)['title'])->toBeNull()
            ->and($a->fresh()->status)->toBe('draft')
            ->and(AdminAuditLog::where('action', 'like', 'house.archived')->count())->toBe(0);
    });
});
