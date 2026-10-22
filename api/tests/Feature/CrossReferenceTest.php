<?php

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\Numbering;
use Illuminate\Support\Facades\DB;

/**
 * The editor's cross-reference endpoint is a read: it never writes a
 * chapter, labels come from saving, and its answer is cached until the
 * book changes.
 */
function xrHeading(string $text, ?string $label = null): array
{
    return array_filter([
        'type' => 'heading',
        'attrs' => array_filter(['level' => 2, 'label' => $label]),
        'content' => [['type' => 'text', 'text' => $text]],
    ]);
}

function xrCaption(string $number, string $text, ?string $label = null): array
{
    return array_filter([
        'type' => 'paragraph',
        'attrs' => $label ? ['label' => $label] : null,
        'content' => [
            ['type' => 'text', 'text' => "Table {$number}", 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => " {$text}"],
        ],
    ]);
}

function xrDoc(array ...$nodes): array
{
    return ['type' => 'doc', 'content' => $nodes];
}

/** A two-chapter book, written to the database without any labels. */
function unlabelledBook(): array
{
    $book = Book::factory()->for(User::factory())->create();
    $a = ManuscriptSection::factory()->for($book)->create(['title' => 'One', 'position' => 10]);
    $b = ManuscriptSection::factory()->for($book)->create(['title' => 'Two', 'position' => 20]);

    // Straight to the table, as content saved before labels were given on
    // save would be.
    DB::table('manuscript_sections')->where('id', $a->id)->update(['content' => json_encode(xrDoc(
        xrHeading('Background'),
        xrCaption('1', 'Old text A'),
    ))]);
    DB::table('manuscript_sections')->where('id', $b->id)->update(['content' => json_encode(xrDoc(
        xrHeading('Method'),
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Chapter two.']]],
    ))]);

    return [$book, $a->fresh(), $b->fresh()];
}

it('never writes a chapter when the editor asks for cross-references', function () {
    [$book, $a, $b] = unlabelledBook();
    $before = DB::table('manuscript_sections')->whereIn('id', [$a->id, $b->id])->pluck('content', 'id');

    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(update|insert|delete)\b.*manuscript_sections/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $this->actingAs($book->user)
        ->getJson("/books/{$book->id}/cross-references?section={$b->id}")
        ->assertOk();

    expect($writes)->toBe([])
        ->and(DB::table('manuscript_sections')->whereIn('id', [$a->id, $b->id])->pluck('content', 'id')->all())
        ->toBe($before->all());
});

it('cannot undo an autosave that lands while the book is being read', function () {
    [$book, $a, $b] = unlabelledBook();
    $new = xrDoc(xrHeading('Background', 'sec-kept'), xrCaption('1', 'NEW text the author just typed', 'tbl-kept'));
    $saved = false;

    // The scenario the database audit reproduced: chapter A is autosaved
    // between the endpoint reading the book and anything it might write.
    DB::listen(function ($query) use (&$saved, $a, $new) {
        if (! $saved && str_contains($query->sql, 'from "manuscript_sections"') && str_contains($query->sql, 'select *')) {
            $saved = true;
            app(\App\Services\ManuscriptWriter::class)->save($a, $new);
        }
    });

    $this->actingAs($book->user)
        ->getJson("/books/{$book->id}/cross-references?section={$b->id}")
        ->assertOk();

    expect($saved)->toBeTrue()
        ->and($a->fresh()->content['content'][1]['content'][1]['text'])->toBe(' NEW text the author just typed');
});

it('labels captions, numbered equations and headings when content is saved', function () {
    $book = Book::factory()->for(User::factory())->create();
    $section = ManuscriptSection::factory()->for($book)->create();

    $this->actingAs($book->user)
        ->putJson("/sections/{$section->id}/content", ['content' => xrDoc(
            xrHeading('Kept', 'sec-mine'),
            xrHeading('New heading'),
            xrCaption('1', 'A table'),
            ['type' => 'blockMath', 'attrs' => ['latex' => 'E = mc^2 \\tag{1}']],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Plain prose.']]],
        )])
        ->assertOk();

    $nodes = $section->fresh()->content['content'];

    expect($nodes[0]['attrs']['label'])->toBe('sec-mine')
        ->and($nodes[1]['attrs']['label'])->toStartWith('sec-')
        ->and($nodes[2]['attrs']['label'])->toStartWith('tbl-')
        ->and($nodes[3]['attrs']['label'])->toStartWith('eq-')
        ->and($nodes[4]['attrs']['label'] ?? null)->toBeNull()
        // Labels are not words: one save, no version, the text as sent.
        ->and($section->versions()->count())->toBe(0)
        ->and($nodes[2]['content'][1]['text'])->toBe(' A table');
});

it('labels content saved before labels were given on save, once, under a lock', function () {
    [$book, $a] = unlabelledBook();
    $stamp = DB::table('manuscript_sections')->where('id', $a->id)->value('updated_at');

    (require database_path('migrations/2026_10_22_130000_label_cross_reference_targets.php'))->up();

    $nodes = $a->fresh()->content['content'];

    expect($nodes[0]['attrs']['label'])->toStartWith('sec-')
        ->and($nodes[1]['attrs']['label'])->toStartWith('tbl-')
        ->and($nodes[1]['content'][1]['text'])->toBe(' Old text A')
        ->and(DB::table('manuscript_sections')->where('id', $a->id)->value('updated_at'))->toBe($stamp)
        ->and($a->versions()->count())->toBe(0);
});

it('serves the book’s numbering from the cache until the book changes', function () {
    [$book, $a, $b] = unlabelledBook();
    $user = $book->user;

    $this->actingAs($user)->getJson("/books/{$book->id}/cross-references?section={$b->id}")->assertOk();

    $reads = 0;
    DB::listen(function ($query) use (&$reads) {
        if (preg_match('/select \* from "manuscript_sections"/', $query->sql)) {
            $reads++;
        }
    });

    $this->actingAs($user)->getJson("/books/{$book->id}/cross-references?section={$b->id}")->assertOk();
    expect($reads)->toBe(0);

    // A save anywhere in the book is seen at once.
    app(\App\Services\ManuscriptWriter::class)->save($a->fresh(), xrDoc(
        xrHeading('Background'),
        xrCaption('1', 'Old text A'),
        xrCaption('2', 'A second table', 'tbl-second'),
    ));

    $targets = $this->actingAs($user)
        ->getJson("/books/{$book->id}/cross-references?section={$b->id}")
        ->assertOk()
        ->json('targets');

    expect($reads)->toBeGreaterThan(0)
        ->and(collect($targets)->pluck('label'))->toContain('tbl-second');
});

it('gives the scheme without the open chapter, and the running offsets', function () {
    $book = Book::factory()->for(User::factory())->create();
    $one = ManuscriptSection::factory()->for($book)->create(['title' => 'One', 'position' => 10, 'content' => xrDoc(
        xrCaption('1', 'First table'),
        xrCaption('2', 'Second table'),
    )]);
    $two = ManuscriptSection::factory()->for($book)->create(['title' => 'Two', 'position' => 20, 'content' => xrDoc(
        xrCaption('2.1', 'A caption the author numbered by chapter'),
    )]);

    $response = $this->actingAs($book->user)
        ->getJson("/books/{$book->id}/cross-references?section={$two->id}")
        ->assertOk();

    // The saved book numbers by chapter because of chapter two's caption;
    // the editor open on chapter two decides from what is on its screen.
    expect($response->json('scheme'))->toBe('chapter')
        ->and($response->json('scheme_elsewhere'))->toBe('continuous')
        ->and($response->json('section.prefix'))->toBe('2.')
        ->and($response->json('section.offsets.table'))->toBe(0)
        ->and($response->json('section.running.table'))->toBe(2);

    expect($this->actingAs($book->user)
        ->getJson("/books/{$book->id}/cross-references?section={$one->id}")
        ->json('scheme_elsewhere'))->toBe('chapter');
});

it('reads the numbering scheme the same way from one document as from a book', function () {
    expect(Numbering::numbersByChapter(xrDoc(xrCaption('3', 'x'))))->toBeFalse()
        ->and(Numbering::numbersByChapter(xrDoc(xrCaption('3.1', 'x'))))->toBeTrue()
        ->and(Numbering::numbersByChapter(xrDoc(['type' => 'blockMath', 'attrs' => ['latex' => 'a \\tag{3.1}']])))->toBeTrue()
        ->and(Numbering::numbersByChapter(null))->toBeFalse()
        ->and(Numbering::label(xrDoc(xrCaption('1', 'x', 'tbl-a'))))->toBeNull();
});
