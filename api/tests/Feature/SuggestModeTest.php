<?php

use App\Models\Book;
use App\Models\BookExport;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\Exporter;
use App\Publishing\PrintBook;
use App\Publishing\SubmissionCheck;
use App\Publishing\Suggestions;
use Illuminate\Support\Facades\Storage;

/**
 * Suggest mode: tracked changes stay in the manuscript until somebody decides
 * them, and nothing a reader receives prints them as if they had been.
 *
 * The rule under test: every renderer reads a pending suggestion as rejected
 * — inserted text left out, deleted text printed as ordinary text.
 */
function change(string $type, string $id = 'c1'): array
{
    return ['type' => $type, 'attrs' => ['id' => $id, 'author' => 'Ana', 'authorId' => 1, 'at' => '2026-10-23T10:00:00Z']];
}

/** "The cat sat [on the mat] [beside the fire]." — mat deleted, fire inserted. */
function suggestedDoc(): array
{
    return ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'The cat sat '],
            ['type' => 'text', 'text' => 'on the mat', 'marks' => [change('deletion', 'c1')]],
            ['type' => 'text', 'text' => 'beside the fire', 'marks' => [change('insertion', 'c1')]],
            ['type' => 'text', 'text' => '. It was '],
            ['type' => 'text', 'text' => 'very ', 'marks' => [change('insertion', 'c2')]],
            ['type' => 'text', 'text' => 'happy.'],
        ]],
        // A paragraph typed whole as a suggestion.
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'SUGGESTEDPARAGRAPH added later.', 'marks' => [change('insertion', 'c3')]],
        ]],
    ]];
}

function suggestedBook(): Book
{
    $book = Book::factory()->for(User::factory())->create([
        'title' => 'Tracked',
        'author_name' => 'A Writer',
        'language' => 'English (US)',
    ]);
    $book->sections()->delete();

    ManuscriptSection::factory()->for($book)->create([
        'title' => 'Chapter One',
        'type' => 'chapter',
        'position' => 0,
        'content' => suggestedDoc(),
    ]);

    return $book->fresh();
}

it('reads a pending suggestion as rejected', function () {
    $published = Suggestions::published(suggestedDoc());

    expect($published['content'])->toHaveCount(1)
        ->and($published['content'][0]['content'])->toBe([
            // Joined again: the deletion mark is gone and the text is plain.
            ['type' => 'text', 'text' => 'The cat sat on the mat. It was happy.'],
        ]);
});

it('can read every suggestion as accepted', function () {
    $accepted = Suggestions::accepted(suggestedDoc());

    expect($accepted['content'][0]['content'][0]['text'])->toBe('The cat sat beside the fire. It was very happy.')
        ->and($accepted['content'][1]['content'][0]['text'])->toBe('SUGGESTEDPARAGRAPH added later.')
        ->and($accepted['content'][1]['content'][0])->not->toHaveKey('marks');
});

it('counts a replacement as one change', function () {
    expect(Suggestions::count(suggestedDoc()))->toBe(3)
        ->and(Suggestions::count(['type' => 'doc', 'content' => []]))->toBe(0)
        ->and(Suggestions::count(null))->toBe(0);
});

it('keeps other marks and leaves a document without suggestions alone', function () {
    $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
        ['type' => 'text', 'text' => 'hel', 'marks' => [['type' => 'bold']]],
        ['type' => 'text', 'text' => 'XX', 'marks' => [['type' => 'bold'], change('insertion')]],
        ['type' => 'text', 'text' => 'lo', 'marks' => [['type' => 'bold'], change('deletion', 'c9')]],
    ]]]];

    expect(Suggestions::published($doc)['content'][0]['content'])
        ->toBe([['type' => 'text', 'text' => 'hello', 'marks' => [['type' => 'bold']]]]);

    $plain = ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
    expect(Suggestions::published($plain))->toBe($plain);
});

it('keeps a table cell valid when its only text was a suggestion', function () {
    $doc = ['type' => 'doc', 'content' => [['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
        ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'new', 'marks' => [change('insertion')]],
        ]]]],
    ]]]]]];

    expect(Suggestions::published($doc)['content'][0]['content'][0]['content'][0]['content'])
        ->toBe([['type' => 'paragraph']]);
});

it('counts the words of the book as it stands', function () {
    // "The cat sat on the mat. It was happy." — nine words.
    expect(ManuscriptSection::countWords(suggestedDoc()))->toBe(9);

    $section = suggestedBook()->sections()->first();
    expect($section->plainText())->toBe('The cat sat on the mat. It was happy.')
        ->and($section->plainText(withSuggestions: true))->toContain('beside the fire');
});

it('prints no pending insertion in HTML, Markdown, EPUB or DOCX', function (string $format) {
    $export = app(Exporter::class)->generate(suggestedBook(), $format);
    $path = Storage::disk('local')->path($export->path);

    if (in_array($format, ['epub', 'docx'], true)) {
        $zip = new ZipArchive;
        $zip->open($path);
        $text = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $text .= $zip->getFromIndex($i);
        }
        $zip->close();
    } else {
        $text = file_get_contents($path);
    }

    // Word may split runs; strip tags so the sentence reads through.
    $flat = preg_replace('/\s+/', ' ', strip_tags($text));

    expect($export->status)->toBe('ready')
        ->and($flat)->toContain('on the mat')
        ->and($flat)->not->toContain('beside the fire')
        ->and($flat)->not->toContain('very happy')
        ->and($flat)->not->toContain('SUGGESTEDPARAGRAPH')
        ->and($text)->not->toContain('<ins')
        ->and($text)->not->toContain('<del');
})->with(['html', 'md', 'epub', 'docx']);

it('typesets the print book without pending insertions', function () {
    $book = suggestedBook();
    $html = app(PrintBook::class)->html($book, $book->sections()->get(), 'en');

    // Print sets the opening words in small capitals, in a span of their own.
    expect($html)->toContain('the mat. It was happy.')
        ->not->toContain('beside the fire')
        ->not->toContain('SUGGESTEDPARAGRAPH');
});

it('renders the PDF from the same view', function () {
    $export = app(Exporter::class)->generate(suggestedBook(), 'pdf');

    expect($export->status)->toBe('ready')
        ->and(Storage::disk('local')->size($export->path))->toBeGreaterThan(0);
});

it('leaves pending insertions out of the chapter Markdown export', function () {
    $book = suggestedBook();

    $md = $this->actingAs($book->user)->get("/books/{$book->id}/export.md")->assertOk()->streamedContent();

    expect($md)->toContain('The cat sat on the mat. It was happy.')
        ->not->toContain('beside the fire')
        ->not->toContain('SUGGESTEDPARAGRAPH');
});

it('warns before submission while suggestions are pending, without blocking', function () {
    $book = suggestedBook();
    $check = app(SubmissionCheck::class);

    expect($check->pendingSuggestions($book))->toBe(3)
        ->and($check->warnings($book))->toHaveKey('suggestions')
        ->and($check->warnings($book)['suggestions'])->toStartWith('3 pending suggestions')
        ->and($check->errors($book))->not->toHaveKey('suggestions');

    $warnings = $this->actingAs($book->user)->getJson("/books/{$book->id}/publishing")
        ->assertOk()
        ->json('warnings');
    expect($warnings)->toHaveKey('suggestions');

    // Decided: the warning goes.
    $book->sections()->first()->update(['content' => Suggestions::accepted(suggestedDoc())]);
    expect($check->warnings($book->fresh()))->toBe([]);
});

it('saves suggestions as they are and records a decision as a review version', function () {
    $book = suggestedBook();
    $section = $book->sections()->first();
    $user = $book->user;

    // Pending suggestions are saved verbatim: the canonical JSON keeps them.
    $this->actingAs($user)->putJson("/sections/{$section->id}/content", [
        'content' => suggestedDoc(),
        'reason' => 'autosave',
    ])->assertOk()->assertJson(['word_count' => 9]);

    expect(Suggestions::count($section->fresh()->content))->toBe(3);

    // Accepting all of them goes through the same save, as `review`.
    $this->actingAs($user)->putJson("/sections/{$section->id}/content", [
        'content' => Suggestions::accepted(suggestedDoc()),
        'reason' => 'review',
        'label' => 'Accepted 3 suggestions',
    ])->assertOk()->assertJson(['word_count' => 13]);

    $version = $section->versions()->first();

    expect($version->reason)->toBe('review')
        ->and($version->label)->toBe('Accepted 3 suggestions')
        ->and($version->user_id)->toBe($user->id)
        // The version is the chapter before the decision, suggestions and all.
        ->and(Suggestions::count($version->content))->toBe(3)
        ->and(Suggestions::count($section->fresh()->content))->toBe(0);
});

it('refuses an unknown save reason', function () {
    $book = suggestedBook();
    $section = $book->sections()->first();

    $this->actingAs($book->user)->putJson("/sections/{$section->id}/content", [
        'content' => suggestedDoc(),
        'reason' => 'ai_draft',
    ])->assertUnprocessable();
});

it('still anchors a comment on suggested text', function () {
    $book = suggestedBook();
    $section = $book->sections()->first();

    $response = $this->actingAs($book->user)->postJson("/sections/{$section->id}/comments", [
        'body' => 'Better?',
        'excerpt' => 'beside the fire',
    ])->assertCreated();

    expect($response->json('threads.0.detached'))->toBeFalse();
});

