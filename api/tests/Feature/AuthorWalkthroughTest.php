<?php

/*
 * Author walkthrough fixes (book 733, "The Kite Over Jacmel"): a novel
 * written through the browser only, on the fake editorial driver.
 */

use App\Editorial\EditorialAgent;
use App\Editorial\ReviewRunner;
use App\Models\Book;
use App\Models\EditorialFinding;
use App\Models\EditorialReview;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Publishing\AiDisclosure;
use App\Publishing\Anatomy;

function awDoc(string ...$paragraphs): array
{
    return ['type' => 'doc', 'content' => array_map(
        fn ($p) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $p]]],
        $paragraphs,
    )];
}

it('keeps the author on the title page when no house name is printed there', function () {
    config(['app.name' => 'Écrivain']);
    $novel = Book::factory()->for(User::factory())->create(['author_name' => 'Écrivain', 'series_name' => null, 'publication' => []]);
    $titlePage = collect(Anatomy::plan($novel)['pages'])->firstWhere('part', 'title_page');

    expect(Anatomy::titlePageAuthor($novel))->toBe('Écrivain')
        ->and($titlePage['data']['author'])->toBe('Écrivain');
});

it('still names no author when the same house is printed on the title page', function () {
    $prepa = Book::factory()->make(['author_name' => 'Écrivain', 'series_name' => 'Écrivain Prépa', 'publication' => []]);
    $imprint = Book::factory()->make(['author_name' => 'Maison Lumière', 'publication' => ['imprint' => 'Maison Lumière']]);

    expect(Anatomy::titlePageAuthor($prepa))->toBeNull()
        ->and(Anatomy::titlePageAuthor($imprint))->toBeNull()
        ->and(Anatomy::titlePageAuthor(Book::factory()->make(['author_name' => '', 'series_name' => 'Écrivain Prépa'])))->toBeNull();
});

it('does not count the sample driver\'s fixes as AI, nor label them AI', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create([
        'content' => awDoc('The kite rose at dawn.  Nurses came early. She was really tired by noon.'),
    ]);

    app(ReviewRunner::class)->run($section, EditorialAgent::Copy, $user->id);
    $fixes = EditorialFinding::where('manuscript_section_id', $section->id)->whereNotNull('suggestion')->get();
    expect($fixes)->not->toBeEmpty();

    foreach ($fixes as $fix) {
        $this->actingAs($user)->patchJson("/findings/{$fix->id}", ['status' => 'accepted'])->assertOk();
    }

    $evidence = AiDisclosure::evidence($book->fresh());
    expect($evidence['text']['accepted_ai_fixes'])->toBe(0)
        ->and($evidence['text']['ai_suggestions_applied'])->toBe(0)
        ->and(AiDisclosure::suggested($evidence)['text'])->toBe('none')
        ->and($section->versions()->where('reason', 'ai_suggestion')->exists())->toBeFalse()
        ->and($section->versions()->where('reason', 'check_fix')->count())->toBe($fixes->count())
        // Each fix points at the snapshot it replaced, not the chapter's first fix.
        ->and(EditorialFinding::whereIn('id', $fixes->pluck('id'))->pluck('applied_version_id')->unique()->count())->toBe($fixes->count());
});

it('counts a model\'s accepted fix once, as AI-assisted', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create(['content' => awDoc('The kite was very very high.')]);
    // A real provider's review, as the runner records it (driver and model).
    $review = EditorialReview::create(['manuscript_section_id' => $section->id, 'agent' => 'line', 'status' => 'completed',
        'driver' => 'anthropic', 'model' => 'claude-sonnet-5-5']);
    $finding = EditorialFinding::create(['editorial_review_id' => $review->id, 'manuscript_section_id' => $section->id, 'agent' => 'line',
        'severity' => 'suggestion', 'title' => 'Repeated word', 'detail' => 'd', 'excerpt' => 'very very', 'suggestion' => 'very', 'source' => 'agent']);
    // A house-style check under the same review is not AI either.
    $style = EditorialFinding::create(['editorial_review_id' => $review->id, 'manuscript_section_id' => $section->id, 'agent' => 'copy',
        'severity' => 'suggestion', 'title' => 'House style', 'detail' => 'd', 'excerpt' => 'kite', 'suggestion' => 'Kite', 'source' => 'style_check']);

    $this->actingAs($user)->patchJson("/findings/{$finding->id}", ['status' => 'accepted'])->assertOk();
    $this->actingAs($user)->patchJson("/findings/{$style->id}", ['status' => 'accepted'])->assertOk();

    $evidence = AiDisclosure::evidence($book->fresh());
    expect($evidence['text']['accepted_ai_fixes'])->toBe(1)
        ->and($evidence['text']['ai_suggestions_applied'])->toBe(0)
        ->and(AiDisclosure::suggested($evidence)['text'])->toBe('assisted')
        ->and($section->versions()->where('reason', 'ai_suggestion')->count())->toBe(1)
        ->and($section->versions()->where('reason', 'check_fix')->count())->toBe(1);
});

it('shows "Draft with me" as coming soon and does not let it be chosen', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create(['assistance' => 'author_led']);

    $this->actingAs($user)->patchJson("/books/{$book->id}/assistance", ['assistance' => 'collaborative'])
        ->assertUnprocessable();
    $journey = $this->actingAs($user)->getJson("/books/{$book->id}/journey")->json();
    $draft = collect($journey['assistance_levels'] ?? $journey['journey']['assistance_levels'] ?? [])->firstWhere('key', 'collaborative');

    expect($book->fresh()->assistance)->toBe('author_led')
        ->and($draft['available'])->toBeFalse();
});

it('names the chapters of a book with no type from a generic shape', function () {
    $user = User::factory()->create();
    // What Quick Start makes: no type, one chapter.
    $this->actingAs($user)->post('/books', ['title' => 'The Kite Over Jacmel', 'quick' => true]);
    $book = $user->books()->firstOrFail();
    $book->sections()->create(['type' => 'chapter', 'title' => 'Untitled chapter', 'position' => 2000]);

    expect($book->book_type)->toBeNull();
    $this->actingAs($user)->postJson("/books/{$book->id}/outline")->assertOk()->assertJsonPath('renamed', 2);

    expect($book->sections()->where('type', 'chapter')->orderBy('position')->pluck('title')->all())
        ->toBe(['1. Beginnings', '2. First steps']);
});

it('fits the outline to the genre and never splices the premise into titles', function () {
    expect(App\Journey\OutlineBuilder::arcKey('nonfiction', 'Self-help'))->toBe('self_help')
        ->and(App\Journey\OutlineBuilder::arcKey('nonfiction', 'Nonfiction'))->toBe('nonfiction')
        ->and(App\Journey\OutlineBuilder::arcKey(null, 'Middle grade'))->toBe('fiction')
        ->and(App\Journey\OutlineBuilder::arcKey('fiction', 'Business'))->toBe('fiction')
        ->and(App\Journey\OutlineBuilder::arcKey(null, null))->toBe('generic');

    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create(['book_type' => 'nonfiction', 'genre' => 'Self-help', 'premise' => 'Adults with a goal that matters']);
    foreach ([1, 2, 3] as $n) {
        $book->sections()->create(['type' => 'chapter', 'title' => "Chapter {$n}", 'position' => $n * 1000]);
    }

    $this->actingAs($user)->postJson("/books/{$book->id}/outline")->assertOk();
    $titles = implode(' | ', $book->sections()->orderBy('position')->pluck('title')->all());

    expect($titles)->toBe('1. Where you are now | 2. Why change is hard | 3. A different way to see it')
        ->not->toContain('goal that matters');
});

it('lets Book Settings set the book type', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create(['book_type' => null]);

    $this->actingAs($user)->get("/books/{$book->id}/settings")->assertOk()
        ->assertInertia(fn ($page) => $page->where('book.book_type', null)->has('book_types'));
    $this->actingAs($user)->patch("/books/{$book->id}", ['book_type' => 'fiction'])->assertRedirect();
    $this->actingAs($user)->patch("/books/{$book->id}", ['book_type' => 'not-a-type'])->assertSessionHasErrors('book_type');

    expect($book->fresh()->book_type)->toBe('fiction');
});

/* ------------------------------------------------------------ find and replace */

function awBookWithChapters(User $user): array
{
    $book = Book::factory()->for($user)->create();
    $one = ManuscriptSection::factory()->for($book)->create(['title' => 'Kite', 'position' => 1000, 'content' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'Ti Jak flew the '],
            ['type' => 'text', 'text' => 'kite', 'marks' => [['type' => 'bold']]],
            ['type' => 'text', 'text' => ' over Jacmel. Kites everywhere; the KITE rose.'],
        ]],
    ]]]);
    $two = ManuscriptSection::factory()->for($book)->create(['title' => 'Sea', 'position' => 2000, 'content' => awDoc('The sea had no kite at all.')]);
    $three = ManuscriptSection::factory()->for($book)->create(['title' => 'Home', 'position' => 3000, 'content' => awDoc('Nothing to find here.')]);

    return [$book, $one, $two, $three];
}

it('finds across the manuscript by chapter, with case and whole-word options', function () {
    $user = User::factory()->create();
    [$book, $one, $two] = awBookWithChapters($user);

    $all = $this->actingAs($user)->getJson("/books/{$book->id}/find?q=kite")->assertOk();
    expect($all->json('total'))->toBe(4)
        ->and(collect($all->json('sections'))->pluck('count', 'id')->all())->toBe([$one->id => 3, $two->id => 1])
        ->and($all->json('sections.0.snippets.0'))->toContain('flew the kite over');

    expect($this->actingAs($user)->getJson("/books/{$book->id}/find?q=kite&word=1")->json('total'))->toBe(3)
        ->and($this->actingAs($user)->getJson("/books/{$book->id}/find?q=kite&word=1&case=1")->json('total'))->toBe(2);

    $this->actingAs(User::factory()->create())->getJson("/books/{$book->id}/find?q=kite")->assertForbidden();
});

it('replaces in every chapter, keeping a version of each changed chapter first', function () {
    $user = User::factory()->create();
    [$book, $one, $two, $three] = awBookWithChapters($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/replace", ['q' => 'kite', 'replacement' => 'cerf-volant', 'word' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('confirmed');

    $response = $this->actingAs($user)->postJson("/books/{$book->id}/replace", [
        'q' => 'kite', 'replacement' => 'cerf-volant', 'word' => true, 'confirmed' => true, 'section' => $one->id,
    ])->assertOk();

    expect($response->json('replaced'))->toBe(3)
        ->and($response->json('current.id'))->toBe($one->id)
        ->and($one->fresh()->plainText())->toBe('Ti Jak flew the cerf-volant over Jacmel. Kites everywhere; the cerf-volant rose.')
        ->and($two->fresh()->plainText())->toBe('The sea had no cerf-volant at all.')
        // The bold stays on the replaced word.
        ->and($one->fresh()->content['content'][0]['content'][1])->toEqual(['type' => 'text', 'text' => 'cerf-volant', 'marks' => [['type' => 'bold']]])
        ->and($one->versions()->where('reason', 'replace')->count())->toBe(1)
        ->and($two->versions()->where('reason', 'replace')->first()->content['content'][0]['content'][0]['text'])->toBe('The sea had no kite at all.')
        ->and($three->versions()->count())->toBe(0);
});

it('finds a phrase split across marks and registers collision-free shortcuts', function () {
    $user = User::factory()->create();
    [$book, $one] = awBookWithChapters($user);

    expect($this->actingAs($user)->getJson("/books/{$book->id}/find?q=".urlencode('the kite over'))->json('total'))->toBe(1);

    $registry = json_decode(file_get_contents(resource_path('js/prefs.json')), true);
    $shortcuts = collect($registry['shortcuts'])->whereIn('scope', ['editor', 'global']);
    $replace = $shortcuts->firstWhere('id', 'editor.replace');

    expect($shortcuts->firstWhere('id', 'editor.find')['keys'])->toBe(['Mod+KeyF'])
        ->and($replace['keys'])->toBe(['Mod+Shift+KeyH'])
        ->and($shortcuts->flatMap(fn ($s) => $s['keys'])->duplicates()->all())->toBe([]);
});
