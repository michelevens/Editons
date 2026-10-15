<?php

use App\Editorial\ConsistencyIndex;
use App\Journey\Coach;
use App\Models\Book;
use Illuminate\Support\Facades\Artisan;

/**
 * The worked example.
 *
 * The demo book is the product's only shop window, and four separate bugs
 * had quietly hollowed it out: every chapter titled "Chapter 7" in the
 * binder, h3 subsections flattened to body text, parts sorting after the
 * chapters they contained, and back matter landing mid-book. All four
 * returned a book that looked plausible in a list and fell apart on screen.
 *
 * These tests assert the properties that make it a usable demonstration,
 * not the prose. A chapter can be rewritten freely; a chapter named
 * "Chapter 7" or a part positioned after its chapters is a regression.
 */
beforeEach(function () {
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $this->book = Book::where('title', 'Building an Independent Practice')
        ->latest('id')
        ->firstOrFail();
});

it('names every chapter', function () {
    // The title was hardcoded to "Chapter {$number}" and the real one lived
    // only inside the prose, so the binder was twenty identical rows and
    // "name the chapters" believed the book was unnamed.
    $generic = $this->book->sections()
        ->where('type', 'chapter')
        ->where('title', 'like', 'Chapter %')
        ->pluck('title');

    expect($generic)->toBeEmpty();
});

it('puts every chapter inside a part', function () {
    $orphans = $this->book->sections()
        ->where('type', 'chapter')
        ->whereNull('parent_id')
        ->count();

    expect($orphans)->toBe(0);
});

it('sorts each part above the chapters it holds', function () {
    // Parts were positioned with a step of 100 while chapters used
    // POSITION_STEP of 10, so every part sorted past its own contents.
    $sections = $this->book->sections()->orderBy('position')->get();

    foreach ($sections->where('type', 'part') as $part) {
        $children = $sections->where('parent_id', $part->id);

        expect($children)->not->toBeEmpty("[{$part->title}] holds no chapters.");

        foreach ($children as $child) {
            expect($part->position)->toBeLessThan(
                $child->position,
                "[{$part->title}] sorts after its own chapter [{$child->title}].",
            );
        }
    }
});

it('keeps the back matter after every chapter', function () {
    $lastChapter = $this->book->sections()->where('type', 'chapter')->max('position');
    $firstBack = $this->book->sections()->where('type', 'back_matter')->min('position');

    expect($firstBack)->toBeGreaterThan($lastChapter);
});

it('keeps the front matter before every chapter', function () {
    $firstChapter = $this->book->sections()->where('type', 'chapter')->min('position');
    $lastFront = $this->book->sections()->where('type', 'front_matter')->max('position');

    expect($lastFront)->toBeLessThan($firstChapter);
});

it('renders subsections as headings rather than paragraphs', function () {
    // The builder handled h2 and let h3 fall through to the paragraph
    // branch, so every subsection the structure declared was body text.
    $json = $this->book->sections()->where('type', 'chapter')->pluck('content')->toJson();

    expect(substr_count($json, '"level":3'))->toBeGreaterThan(10)
        ->and(substr_count($json, '"level":2'))->toBeGreaterThan(20);
});

it('gives every chapter enough prose to review', function () {
    // The point of the rewrite. At twenty-eight words a chapter the
    // editorial agents, the exporter and the Coach all had nothing to work
    // on, so none of the screens that depend on them could be evaluated.
    $thin = $this->book->sections()
        ->where('type', 'chapter')
        ->where('word_count', '<', 150)
        ->pluck('title');

    expect($thin)->toBeEmpty();
});

it('has twenty chapters across four parts', function () {
    expect($this->book->sections()->where('type', 'chapter')->count())->toBe(20)
        ->and($this->book->sections()->where('type', 'part')->count())->toBe(4);
});

it('keeps the planted defects the editorial agents are judged against', function () {
    // These are fixtures, not mistakes. Tidying them would make the
    // copy-editor, line-editor and fact-checker screens return nothing,
    // and a reviewer would conclude the agents were broken.
    $text = $this->book->sections()->get()->map->plainText()->implode(' ');

    foreach ([
        'The the',                      // copy editor
        'practicioner',                 // copy editor, and a declared variant
        'it was decided by the board',  // line editor: passive voice
        '47 percent',                   // fact checker: unsourced figure
        'Board of Directors',           // consistency: a declared variant
    ] as $fixture) {
        $this->assertStringContainsString(
            $fixture,
            $text,
            "The [{$fixture}] fixture is gone, so the screen that depends on it shows nothing.",
        );
    }
});

it('gives the consistency index real conflicts to find', function () {
    expect(app(ConsistencyIndex::class)->scan($this->book))->not->toBeEmpty();
});

it('reads as an evenly paced book', function () {
    // The Coach is the honest check on the demo: it reports stubs and
    // uneven pacing from the word counts alone, so a demo book it
    // complains about is a demo book that looks unfinished on screen.
    $keys = collect(app(Coach::class)->forBook($this->book))->pluck('key');

    expect($keys)->not->toContain('stubs')
        ->and($keys)->not->toContain('uneven')
        ->and($keys)->not->toContain('unstarted');
});

it('shows three books in three different states', function () {
    // The shelf exists to demonstrate the product, and three books all in the
    // same state demonstrates one thing three times. Each of these is a stage
    // the journey model is about: finished draft, part written, outline with
    // the opening chapters done.
    $user = $this->book->user;

    $states = $user->books()->get()->mapWithKeys(function (Book $book) {
        $chapters = $book->sections()->where('type', 'chapter')->count();
        $written = $book->sections()->where('type', 'chapter')->where('word_count', '>', 0)->count();

        return [$book->title => [$written, $chapters]];
    });

    expect($states)->toHaveCount(3);

    // Every chapter written.
    expect($states['Building an Independent Practice'][0])
        ->toBe($states['Building an Independent Practice'][1]);

    // Part written, and genuinely part: some chapters have nothing in them.
    [$written, $chapters] = $states['The Quiet Hours'];
    expect($written)->toBeGreaterThan(0)->and($written)->toBeLessThan($chapters);

    [$written, $chapters] = $states['Small Systems'];
    expect($written)->toBeGreaterThan(0)->and($written)->toBeLessThan($chapters);
});

it('leaves an unwritten chapter genuinely empty', function () {
    // The heading was written unconditionally, so a chapter deliberately left
    // unwritten still carried two to four words and the binder, the Coach and
    // the Director all counted it as started.
    $book = $this->book->user->books()->where('title', 'The Quiet Hours')->firstOrFail();

    $planned = $book->sections()
        ->where('type', 'chapter')
        ->where('title', 'The Last Night')
        ->firstOrFail();

    expect($planned->word_count)->toBe(0)
        ->and(trim($planned->plainText()))->toBe('');
});

it('gives the Coach nothing to complain about that is not true', function () {
    // The demo is the product's shop window, so the Coach must not report
    // stubs in a book whose short chapters were a seeding artefact rather
    // than a real pacing problem.
    foreach ($this->book->user->books()->get() as $book) {
        $keys = collect(app(Coach::class)->forBook($book))->pluck('key');

        expect($keys)->not->toContain('stubs', "[{$book->title}] reports stub chapters.");
    }
});
