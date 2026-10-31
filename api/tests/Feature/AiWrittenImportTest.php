<?php

use App\Models\Book;
use App\Models\ManuscriptVersion;
use App\Models\User;
use App\Publishing\AiDisclosure;
use Illuminate\Http\UploadedFile;

/*
 * Imports marked as written with an AI tool, and section types chosen in
 * the import preview (ADR — AI-written imports). Both opt-in: an import
 * that leaves them alone is what it always was.
 */

const AI_MANUSCRIPT = "# Introduction\n\nWhy this book.\n\n# Part One: Roots\n\n## Chapter 1: Soil\n\nDirt.\n\n## Chapter 2: Water\n\nRain.\n\n# Appendix\n\nTables.\n\n# Glossary\n\nWords.\n";

function aiMarkdownFile(string $name = 'field-guide.md', string $text = AI_MANUSCRIPT): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'md');
    file_put_contents($path, $text);

    return new UploadedFile($path, $name, null, null, true);
}

function emptyBook(User $user): Book
{
    $book = Book::factory()->for($user)->create();
    $book->sections()->forceDelete();

    return $book;
}

/** @return list<string> */
function versionReasons(Book $book): array
{
    return ManuscriptVersion::whereIn('manuscript_section_id', $book->sections()->pluck('id'))->pluck('reason')->unique()->values()->all();
}

it('imports as before when the AI box is left off: chapters, no AI evidence', function () {
    $user = User::factory()->create();
    $book = emptyBook($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/import", ['file' => aiMarkdownFile()])->assertCreated();

    $book->refresh();
    expect($book->sections()->orderBy('position')->pluck('type')->unique()->all())->toBe(['chapter'])
        ->and(ManuscriptVersion::whereIn('manuscript_section_id', $book->sections()->pluck('id'))->where('reason', 'ai_draft')->count())->toBe(0)
        ->and(AiDisclosure::evidence($book)['text']['ai_drafts'])->toBe(0)
        ->and(AiDisclosure::suggested(AiDisclosure::evidence($book))['text'])->toBe('none');
});

it('saves each section\'s first version as an AI draft naming the import when the box is ticked', function () {
    $user = User::factory()->create();
    $book = emptyBook($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/import", ['file' => aiMarkdownFile(), 'ai_written' => true])->assertCreated();

    $book->refresh();
    $sections = $book->sections()->orderBy('position')->get();
    expect($sections)->toHaveCount(6);

    // "Part One: Roots" has a title and no text, so there is nothing to
    // version; every section with text starts as an AI draft.
    foreach ($sections->reject(fn ($s) => $s->title === 'Part One: Roots') as $section) {
        $first = $section->versions()->reorder()->orderBy('version_number')->first();
        expect($first->reason)->toBe('ai_draft')
            ->and($first->label)->toBe('Imported from “field-guide.md”, written with an AI tool')
            ->and($first->user_id)->toBe($user->id);
    }

    expect($sections->first()->plainText())->toContain('Why this book.');

    // The same records the KDP AI questions read.
    $evidence = AiDisclosure::evidence($book);
    expect($evidence['text']['ai_drafted_chapters'])->toBe(5)
        ->and(AiDisclosure::suggested($evidence)['text'])->toBe('generated');
});

it('marks a new book created from a file on the shelf as AI-written too', function () {
    $user = User::factory()->create(['plan' => 'studio']);

    $response = $this->actingAs($user)
        ->postJson('/books/import', ['file' => aiMarkdownFile('Guide.md'), 'ai_written' => '1'])
        ->assertCreated();

    $book = Book::findOrFail($response->json('book.id'));
    expect(versionReasons($book))->toBe(['ai_draft'])
        ->and(AiDisclosure::evidence($book)['text']['ai_drafted_chapters'])->toBe(5);
});

it('labels pasted Markdown marked as AI-written', function () {
    $user = User::factory()->create();
    $book = emptyBook($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/import", ['markdown' => "# One\n\nText.", 'ai_written' => true])->assertCreated();

    expect(ManuscriptVersion::whereIn('manuscript_section_id', $book->sections()->pluck('id'))->sole()->label)
        ->toBe('Imported from pasted text, written with an AI tool');
});

it('previews the sections of a Markdown file without writing anything', function () {
    $user = User::factory()->create();
    $before = Book::count();

    $this->actingAs($user)->postJson('/import/preview', ['file' => aiMarkdownFile()])
        ->assertOk()
        ->assertJsonPath('sections.0', ['title' => 'Introduction', 'type' => 'chapter'])
        ->assertJsonPath('sections.1.title', 'Part One: Roots')
        ->assertJsonPath('sections.2.title', 'Chapter 1: Soil')
        ->assertJsonPath('sections.5.title', 'Glossary')
        ->assertJsonCount(6, 'sections');

    expect(Book::count())->toBe($before);

    // A Word file is not previewed: reading one stores its pictures.
    $this->actingAs($user)->postJson('/import/preview', ['file' => UploadedFile::fake()->create('a.docx', 1)])
        ->assertOk()->assertJsonPath('sections', null);

    $this->app['auth']->forgetGuards();
    $this->postJson('/import/preview', ['file' => aiMarkdownFile()])->assertUnauthorized();
});

it('gives each section the type chosen in the preview; unchosen ones stay chapters', function () {
    $user = User::factory()->create();
    $book = emptyBook($user);

    // # and ## both start a section, so the importer cannot tell a part, a
    // chapter, an introduction or a glossary apart on its own.
    $this->actingAs($user)->postJson("/books/{$book->id}/import", [
        'file' => aiMarkdownFile(),
        'types' => ['front_matter', 'part', 'chapter', 'chapter', 'back_matter'],
    ])->assertCreated();

    expect($book->sections()->orderBy('position')->get(['title', 'type'])->map(fn ($s) => [$s->title, $s->type])->all())->toBe([
        ['Introduction', 'front_matter'],
        ['Part One: Roots', 'part'],
        ['Chapter 1: Soil', 'chapter'],
        ['Chapter 2: Water', 'chapter'],
        ['Appendix', 'back_matter'],
        ['Glossary', 'chapter'],
    ]);

    // Types alone do not mark anything as AI-written.
    expect(versionReasons($book))->not->toContain('ai_draft');

    $this->actingAs($user)->postJson("/books/{$book->id}/import", ['file' => aiMarkdownFile(), 'types' => ['section']])
        ->assertStatus(422);
});
