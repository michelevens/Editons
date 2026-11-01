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

it('shows "Draft with me" as coming soon and does not let it be chosen while the AI writer is off', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create(['assistance' => 'author_led']);
    // On by default since ADR — AI writes the book; off, it is as it was.
    app(\App\Platform\Features::class)->setGlobal('ai_writer', false, 'Off for this test', User::factory()->create());

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

/* ---------------------------------------------------------- agents that read the book */

function awFindings(string $agent, string ...$paragraphs): Illuminate\Support\Collection
{
    $user = User::factory()->create();
    $section = ManuscriptSection::factory()->for(Book::factory()->for($user))->create(['content' => awDoc(...$paragraphs)]);
    app(ReviewRunner::class)->run($section, EditorialAgent::from($agent), $user->id);

    return EditorialFinding::where('manuscript_section_id', $section->id)->get();
}

it('leaves the thesis examiners out of a full review of a novel', function () {
    $user = User::factory()->create();
    $novel = Book::factory()->for($user)->create(['book_type' => 'fiction', 'genre' => 'Middle grade']);
    $thesis = Book::factory()->for($user)->create(['book_type' => 'masters_thesis']);
    $fits = fn (Book $b) => collect(EditorialAgent::catalogue([], $user, $b))->pluck('fits', 'value');

    expect($fits($novel)['masters_examiner'])->toBeFalse()
        ->and($fits($novel)['doctoral_examiner'])->toBeFalse()
        ->and($fits($novel)['line'])->toBeTrue()
        ->and($fits($thesis)['masters_examiner'])->toBeTrue()
        // Unfit agents sort last; still listed, to run by hand.
        ->and(collect(EditorialAgent::catalogue([], $user, $novel))->last()['fits'])->toBeFalse();
});

it('does not treat dialogue as a claim to fact-check', function () {
    $findings = awFindings('fact_checker', '"You always lose the kite," said Ti Jak. "90 percent of kites fall!"', 'The town has 40 percent of the coast.');

    expect($findings)->toHaveCount(1)
        ->and($findings->first()->excerpt)->toContain('40 percent');
});

it('does not call "was red" or "was tired" passive voice', function () {
    $findings = awFindings('line', 'The kite was red. She was tired. The letter was written by her aunt.');
    $passive = $findings->where('title', 'Possible passive voice');

    expect($passive)->toHaveCount(1)
        ->and($passive->first()->excerpt)->toContain('was written');
});

it('counts each line of dialogue as its own sentence', function () {
    $line = '"Look at the kite over the roofs of Jacmel, it climbs higher than the church tower today," she said.';
    $findings = awFindings('line', $line."\n".'"It will fall soon enough, you know, it always does when the wind turns at noon," he said.'."\n".'"Not this one, not today, because I tied the string myself with the knot my grandmother taught me."');

    expect($findings->filter(fn ($f) => str_starts_with($f->title, 'Long sentence')))->toBeEmpty();
});

it('counts sentence openers without the quotation marks', function () {
    $findings = awFindings('developmental', '"She ran." She stopped. “She laughed.” She sat.');

    expect($findings->pluck('title')->all())->toContain('Repeated sentence opening: "she" ×4');
});

it('does not offer "would rather" as a filler to delete', function () {
    $findings = awFindings('copy', 'I would rather fly the kite than sleep. He is rather tall for his age.');
    $rather = $findings->first(fn ($f) => str_starts_with($f->title, 'Filler word: "rather"'));

    expect($rather->title)->toContain('×1')
        ->and($rather->excerpt)->not->toContain('would rather')
        ->and((string) $rather->suggestion)->not->toContain('would fly');
});

it('keeps a dismissed finding dismissed on the next run over unchanged text', function () {
    $user = User::factory()->create();
    $section = ManuscriptSection::factory()->for(Book::factory()->for($user))->create(['content' => awDoc('The kite rose at dawn.  Nurses came early. She was really tired by noon.')]);
    app(ReviewRunner::class)->run($section, EditorialAgent::Copy, $user->id);
    $space = EditorialFinding::where('manuscript_section_id', $section->id)->where('title', 'Double space between sentences')->firstOrFail();
    $this->actingAs($user)->patchJson("/findings/{$space->id}", ['status' => 'dismissed'])->assertOk();
    $open = EditorialFinding::where('manuscript_section_id', $section->id)->where('status', 'open')->count();

    app(ReviewRunner::class)->run($section, EditorialAgent::Copy, $user->id);

    expect(EditorialFinding::where('manuscript_section_id', $section->id)->where('title', 'Double space between sentences')->pluck('status')->all())->toBe(['dismissed'])
        ->and(EditorialFinding::where('manuscript_section_id', $section->id)->where('status', 'open')->count())->toBe($open);
});

it('offers children\'s and other genres, and the juvenile store categories, keeping old values', function () {
    $genres = App\Platform\Trust\MasterData::get('genres');
    $categories = App\Platform\Trust\MasterData::get('store_categories');

    expect($genres)->toContain("Children's", 'Middle grade', 'Young adult', 'Self-help', 'Business', 'Biography & memoir', 'Religion & spirituality', 'Education')
        ->and(array_slice($genres, 0, 5))->toBe(['Nonfiction', 'Memoir', 'Textbook', 'Technical', 'Fiction'])
        ->and($categories)->toContain('Juvenile Fiction / General', 'Juvenile Nonfiction / General', 'Young Adult Fiction / General', 'Self-Help / General',
            'Business & Economics / General', 'Biography & Autobiography / General', 'Religion / General', 'Education / General')
        ->and($categories)->toContain('Fiction / Literary')
        // A value the list does not have stays selectable for the book that has it.
        ->and(App\Platform\Trust\MasterData::with('genres', 'Steampunk'))->toContain('Steampunk');
});

it('lets Cover Studio load its fonts from the configured API origin, and nothing wider', function () {
    putenv('VITE_API_URL=https://api.ecrivain.ai/');
    putenv('CORS_ALLOWED_ORIGINS=https://app.ecrivain.ai');

    try {
        $config = require config_path('security.php');
    } finally {
        putenv('VITE_API_URL');
        putenv('CORS_ALLOWED_ORIGINS');
    }

    expect($config['csp']['font_src'])->toBe(['https://api.ecrivain.ai']);

    config(['security.csp.font_src' => $config['csp']['font_src']]);
    $csp = $this->get('/login')->headers->get('Content-Security-Policy');
    preg_match('/font-src ([^;]*)/', $csp, $font);

    expect($font[1])->toBe("'self' data: https://api.ecrivain.ai")
        ->not->toContain('*');
});

/* ------------------------------------------------------------------- URLs */

it('sends the book\'s own address to its studio instead of a 405', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    $this->actingAs($user)->get("/books/{$book->id}")->assertRedirect(route('books.edit', $book));
    $this->actingAs(User::factory()->create())->get("/books/{$book->id}")->assertForbidden();
});

it('opens a studio pane, not its JSON, when its address is loaded in the browser', function (string $path, string $target) {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $html = ['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Sec-Fetch-Mode' => 'navigate'];

    $this->actingAs($user)->get("/books/{$book->id}/{$path}", $html)
        ->assertRedirect(str_replace('{id}', (string) $book->id, $target));

    // The pane's own fetch still gets its data.
    $this->actingAs($user)->getJson("/books/{$book->id}/{$path}")->assertOk();
})->with([
    ['design', '/books/{id}/edit?pane=design'],
    ['publishing', '/books/{id}/edit?pane=publishing'],
    ['rights', '/books/{id}/edit?pane=rights'],
    ['sales', '/books/{id}/edit?pane=sales'],
    ['comments', '/books/{id}/edit?pane=comments'],
    ['journey', '/books/{id}/guided'],
]);

/* ------------------------------------------------------------- small items */

it('puts no drop cap on front or back matter', function () {
    $book = Book::factory()->for(User::factory())->create(['layout' => array_merge(Book::DEFAULT_LAYOUT, ['dropcap' => true])]);
    $chapter = ManuscriptSection::factory()->for($book)->create(['type' => 'chapter', 'title' => 'The Kite', 'position' => 1000, 'content' => awDoc('Wind came off the sea that morning.')]);
    $thanks = ManuscriptSection::factory()->for($book)->create(['type' => 'back_matter', 'title' => 'Acknowledgments', 'position' => 2000, 'content' => awDoc('Many people helped with this book.')]);

    $html = app(App\Publishing\PrintBook::class)->html($book, collect([$chapter, $thanks]), 'en');

    expect(substr_count($html, '<span class="dropcap">'))->toBe(1)
        ->and($html)->toContain('<span class="dropcap">W</span>');
});

it('starts an empty store description from the book\'s description', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create(['description' => 'A boy, a kite and the wind over Jacmel.', 'meta' => []]);

    expect($this->actingAs($user)->getJson("/books/{$book->id}/publishing")->json('book.description'))->toBe('A boy, a kite and the wind over Jacmel.');

    $book->update(['meta' => ['description' => 'The store copy.']]);
    expect($this->actingAs($user)->getJson("/books/{$book->id}/publishing")->json('book.description'))->toBe('The store copy.');
});

it('edits a declared term, keeping its note', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $terms = $this->actingAs($user)->postJson("/books/{$book->id}/terms", ['kind' => 'character', 'canonical' => 'Ti Jak', 'variants' => ['Tijak'], 'note' => 'Eleven, flies kites.'])
        ->assertSuccessful()->json('terms');

    expect($terms[0]['note'])->toBe('Eleven, flies kites.');

    $terms = $this->actingAs($user)->postJson("/books/{$book->id}/terms", ['id' => $terms[0]['id'], 'kind' => 'character', 'canonical' => 'Ti-Jak', 'variants' => ['Tijak', 'Ti Jak'], 'note' => 'Eleven.'])
        ->assertSuccessful()->json('terms');

    expect($terms)->toHaveCount(1)
        ->and($terms[0]['canonical'])->toBe('Ti-Jak')
        ->and($terms[0]['note'])->toBe('Eleven.');

    $other = Book::factory()->for($user)->create();
    $this->actingAs($user)->postJson("/books/{$other->id}/terms", ['id' => $terms[0]['id'], 'kind' => 'character', 'canonical' => 'X'])->assertNotFound();
});

it('tells Guided when the book is a story', function () {
    $user = User::factory()->create();
    $novel = Book::factory()->for($user)->create(['book_type' => null, 'genre' => 'Middle grade']);
    $guide = Book::factory()->for($user)->create(['book_type' => 'nonfiction', 'genre' => 'Nonfiction']);

    $this->actingAs($user)->get("/books/{$novel->id}/guided")->assertInertia(fn ($page) => $page->where('book.fiction', true));
    $this->actingAs($user)->get("/books/{$guide->id}/guided")->assertInertia(fn ($page) => $page->where('book.fiction', false));
});

/* ------------------------------------------------- second walkthrough (book 734) */

it('imports an outline over a scaffold without leaving ghost chapters', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->post('/books', ['title' => 'Piti Piti', 'book_type' => 'nonfiction', 'chapters' => 11]);
    $book = $user->books()->latest('id')->firstOrFail();

    // Prompts are not words: nothing is written yet.
    expect($book->fresh()->word_count)->toBe(0)
        ->and($book->sections()->where('type', 'chapter')->where('word_count', '>', 0)->count())->toBe(0);

    $this->actingAs($user)->postJson("/books/{$book->id}/outline/import", ['outline' => "Start small\nKeep going\nFinish"])->assertOk();

    $live = $book->sections()->get();
    $orphans = $live->filter(fn ($s) => $s->parent_id && ! $live->contains('id', $s->parent_id));

    expect($live->where('type', 'chapter')->pluck('title')->values()->all())->toBe(['Start small', 'Keep going', 'Finish'])
        ->and($orphans)->toBeEmpty();
});

it('soft-deletes a part\'s chapters with it, and the migration clears existing ghosts', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $part = ManuscriptSection::factory()->for($book)->create(['type' => 'part', 'title' => 'Part I', 'position' => 1]);
    $chapter = ManuscriptSection::factory()->for($book)->create(['type' => 'chapter', 'title' => 'One', 'position' => 1000, 'content' => awDoc('Some real words here.')]);
    $chapter->forceFill(['parent_id' => $part->id])->save();

    $part->delete();
    expect($chapter->fresh()->trashed())->toBeTrue();

    // A ghost as the old importer left it: live chapter, deleted parent.
    $ghostPart = ManuscriptSection::factory()->for($book)->create(['type' => 'part', 'title' => 'Part II', 'position' => 2]);
    $ghost = ManuscriptSection::factory()->for($book)->create(['type' => 'chapter', 'title' => 'Ghost', 'position' => 2000, 'content' => awDoc('Hidden words.')]);
    $ghost->forceFill(['parent_id' => $ghostPart->id])->save();
    Illuminate\Support\Facades\DB::table('manuscript_sections')->where('id', $ghostPart->id)->update(['deleted_at' => now()]);
    // A prompt counted as words, as before.
    $prompt = ManuscriptSection::factory()->for($book)->create(['type' => 'chapter', 'title' => 'Two', 'position' => 3000,
        'content' => awDoc(config('booktypes.nonfiction.chapter_prompt'))]);
    $prompt->forceFill(['word_count' => 16])->save();

    (require database_path('migrations/2026_11_09_171000_clear_ghost_sections_and_recount_prompts.php'))->up();

    expect($ghost->fresh()->trashed())->toBeTrue()
        ->and($prompt->fresh()->word_count)->toBe(0)
        ->and($book->fresh()->word_count)->toBe(0);
});

it('does not offer a fix for a deliberate reduplication', function () {
    // Twice in the chapter: a proverb, not a slip.
    $twice = awFindings('copy', 'Piti piti zwazo fè nich li. Se konsa: piti piti, jou apre jou.');
    // Inside quotation marks.
    $quoted = awFindings('copy', 'Grann always said, "piti piti zwazo fè nich li," and smiled.');
    // A real slip still gets its fix.
    $slip = awFindings('copy', 'She walked to the the market at dawn.');

    expect($twice->where('title', 'Repeated word')->pluck('suggestion')->filter()->all())->toBe([])
        ->and($quoted->where('title', 'Repeated word')->pluck('suggestion')->filter()->all())->toBe([])
        ->and($slip->firstWhere('title', 'Repeated word')->suggestion)->not->toBeNull();

    // Once in this chapter but also in another chapter of the book: no fix.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    ManuscriptSection::factory()->for($book)->create(['position' => 1000, 'content' => awDoc('Grann taught us piti piti, slowly.')]);
    $section = ManuscriptSection::factory()->for($book)->create(['position' => 2000, 'content' => awDoc('He climbed piti piti up the hill.')]);
    app(ReviewRunner::class)->run($section, EditorialAgent::Copy, $user->id);

    expect(EditorialFinding::where('manuscript_section_id', $section->id)->where('title', 'Repeated word')->value('suggestion'))->toBeNull();
});

it('counts the generated copyright page as present in the rights checklist', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create(['author_name' => 'Marie Joseph', 'publication' => ['disclaimer' => 'This book is not legal or medical advice.']]);
    ManuscriptSection::factory()->for($book)->create(['type' => 'chapter', 'title' => 'One', 'position' => 1000, 'content' => awDoc('Words.')]);

    $plan = collect(Anatomy::plan($book)['pages'])->firstWhere('part', 'copyright_page');
    $checklist = collect($this->actingAs($user)->getJson("/books/{$book->id}/rights")->assertOk()->json('checklist'))->keyBy('key');

    expect($plan['source'])->toBe('generated')
        ->and($checklist['copyright_page']['done'])->toBeTrue()
        ->and($checklist['disclaimer']['done'])->toBeTrue();
});

it('keeps both Book Settings forms\' unsaved fields when either saves', function () {
    $settings = file_get_contents(resource_path('js/Pages/Books/Settings.jsx'));

    expect(substr_count($settings, 'preserveState: true,'))->toBeGreaterThanOrEqual(4)
        ->and($settings)->toContain("t('fric.unsaved')");
});

it('sends an Inertia visit to a pane URL to the studio page', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();

    // The asset version the client would send; without it Inertia answers 409 for a stale page.
    $version = (string) app(App\Http\Middleware\HandleInertiaRequests::class)->version(Illuminate\Http\Request::create('/'));

    $this->actingAs($user)->get("/books/{$book->id}/design", ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', route('books.edit', ['book' => $book->id, 'pane' => 'design']));
});

it('spells out the large numeral when the label is spelled out', function () {
    $book = Book::factory()->for(User::factory())->create(['language' => 'English (US)']);
    foreach (['One', 'Two', 'Three', 'Four'] as $i => $title) {
        ManuscriptSection::factory()->for($book)->create(['type' => 'chapter', 'title' => "The {$title}", 'position' => ($i + 1) * 1000, 'content' => awDoc('Words.')]);
    }
    $with = fn (string $label) => tap($book->fresh())->update(['layout' => array_merge($book->layout(), [
        'opener' => ['style' => 'numeral', 'label' => $label] + App\Publishing\InteriorDesign::DEFAULTS['opener'],
    ])]);

    $words = app(App\Publishing\PrintBook::class)->html($with('words')->fresh(), $book->sections()->orderBy('position')->get(), 'en');
    $numerals = app(App\Publishing\PrintBook::class)->html($with('numerals')->fresh(), $book->sections()->orderBy('position')->get(), 'en');

    expect($words)->toContain('<p class="opener-figure opener-figure-word">Four</p>')
        ->and($numerals)->toContain('<p class="opener-figure">4</p>');
});

it('applies the language\'s quotes at export by default, keeping the stored text', function () {
    $book = Book::factory()->for(User::factory())->create(['language' => 'English (US)']);
    $section = ManuscriptSection::factory()->for($book)->create(['type' => 'chapter', 'title' => 'One', 'position' => 1000, 'content' => awDoc('"Fly it," she said.')]);

    expect(App\Editorial\Style\Typography::resolve($book)['apply_at_export'])->toBeTrue();

    $file = app(App\Publishing\Exporter::class)->generate($book, 'html');
    expect(Illuminate\Support\Facades\Storage::disk('local')->get($file->path))->toContain('“Fly it,” she said.')
        ->and($section->fresh()->plainText())->toBe('"Fly it," she said.');
});

/* ------------------------------------------------------- provider failures (M) */

it('handles each provider failure gracefully: unchanged credits, a plain message, no secret logged', function (int $status, array $body, string $kind) {
    $logged = [];
    Illuminate\Support\Facades\Log::listen(function ($event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    app()->bind(App\Editorial\Contracts\EditorialDriver::class, fn () => new App\Editorial\Drivers\AnthropicDriver(apiKey: 'sk-ant-api03-SECRETKEY1234567890', model: 'claude-sonnet-5-5'));
    Illuminate\Support\Facades\Http::fake(['api.anthropic.com/*' => Illuminate\Support\Facades\Http::response($body, $status)]);

    $user = User::factory()->subscribed('studio', 500)->create();
    $section = ManuscriptSection::factory()->for(Book::factory()->for($user))->create(['content' => awDoc('The kite rose over Jacmel.')]);

    // On the worker (the sync queue here): the 202 carries the review.
    $response = $this->actingAs($user)->postJson("/sections/{$section->id}/review", ['agent' => 'line'])->assertStatus(202);

    expect($response->json('review.status'))->toBe('failed')
        ->and($response->json('review.unavailable'))->toBe($kind)
        ->and($response->json('review.error'))->toBe('AI review is unavailable right now. Try again later.')
        ->and(json_encode($response->json()))->not->toContain('sk-ant')->not->toContain('credit balance')
        ->and(app(App\Billing\CreditLedger::class)->balanceFor($user))->toBe(500)
        ->and(EditorialFinding::where('manuscript_section_id', $section->id)->count())->toBe(0);

    $log = implode("\n", $logged);
    expect($log)->toContain('editorial.provider_unavailable')->toContain($kind)
        ->not->toContain('SECRETKEY')->not->toContain('anthropic-version');
})->with([
    'billing' => [400, ['type' => 'error', 'error' => ['type' => 'invalid_request_error', 'message' => 'Your credit balance is too low to access the Anthropic API. Key sk-ant-api03-SECRETKEY1234567890']], 'billing'],
    'auth' => [401, ['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 'auth'],
    'rate limit' => [429, ['type' => 'error', 'error' => ['type' => 'rate_limit_error', 'message' => 'Number of requests has exceeded your rate limit']], 'rate_limit'],
    'server' => [529, ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 'server'],
    'timeout' => [504, ['type' => 'error', 'error' => ['type' => 'api_error', 'message' => 'Gateway timeout']], 'timeout'],
]);

it('treats a dropped connection as the provider being unavailable', function () {
    app()->bind(App\Editorial\Contracts\EditorialDriver::class, fn () => new App\Editorial\Drivers\AnthropicDriver(apiKey: 'sk-ant-api03-SECRETKEY1234567890', model: 'claude-sonnet-5-5'));
    Illuminate\Support\Facades\Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('cURL error 28 with header x-api-key: sk-ant-api03-SECRETKEY1234567890'));

    $user = User::factory()->subscribed('studio', 500)->create();
    $section = ManuscriptSection::factory()->for(Book::factory()->for($user))->create(['content' => awDoc('Words here.')]);
    $response = $this->actingAs($user)->postJson("/sections/{$section->id}/review", ['agent' => 'line'])->assertStatus(202);

    expect($response->json('review.unavailable'))->toBe('timeout')
        ->and(app(App\Billing\CreditLedger::class)->balanceFor($user))->toBe(500)
        ->and(App\Editorial\ProviderUnavailable::scrub('key sk-ant-api03-abcdef123456 and sk-proj-zzzzzz999'))->toBe('key sk-[redacted] and sk-[redacted]');
});
