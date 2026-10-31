<?php

use App\Models\Book;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * ADR — Journey friction fixes: the points where a new author, walking from
 * a first book to a finished one through the UI, stalled.
 */

// 1. Plan limit vs. import.

it('says on the register page how many books the free plan includes', function () {
    $this->get('/register')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/Register')
            ->where('offer.books', config('billing.plans.free.books')));

    expect(json_decode(file_get_contents(lang_path('en.json')), true)['jfix.register_free_plan'])
        ->toContain('{books, plural');
});

it('offers an import into the existing book when the shelf is at its limit', function () {
    $user = User::factory()->create(['plan' => 'free']);
    $book = Book::factory()->for($user)->create(['title' => 'The Harbour Clinic']);

    $this->actingAs($user)->get('/books')
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_create', false)
            ->where('import_into.id', $book->id)
            ->where('import_into.title', 'The Harbour Clinic'));

    // The binder's import, which the shelf's offer opens, still takes a file.
    $file = Illuminate\Http\UploadedFile::fake()->createWithContent('more.md', "# Chapter Two\n\nMore words.\n");

    $this->actingAs($user)
        ->post("/books/{$book->id}/import", ['file' => $file], ['Accept' => 'application/json'])
        ->assertSuccessful();

    expect($book->sections()->where('title', 'Chapter Two')->exists())->toBeTrue()
        ->and($user->books()->count())->toBe(1);
});

it('sends no import target while another book can be made', function () {
    $user = User::factory()->subscribed('author')->create();
    Book::factory()->for($user)->create();

    $this->actingAs($user)->get('/books')
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_create', true)
            ->where('import_into', null));
});

// 2. Accepting findings without a provider.

function jfixDoc(string ...$paragraphs): array
{
    return ['type' => 'doc', 'content' => array_map(
        fn ($p) => ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $p]]],
        $paragraphs,
    )];
}

function jfixReview(App\Models\ManuscriptSection $section, string $agent): Illuminate\Support\Collection
{
    app(App\Editorial\ReviewRunner::class)->run($section, App\Editorial\EditorialAgent::from($agent), $section->book->user_id);

    return App\Models\EditorialFinding::where('manuscript_section_id', $section->id)->get();
}

it('gives a double space and a filler word a concrete fix that applies after approval', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = App\Models\ManuscriptSection::factory()->for($book)->create([
        'content' => jfixDoc('The clinic opened at dawn.  Nurses came early. She was really tired by noon.', 'A second paragraph.'),
    ]);

    $findings = jfixReview($section, 'copy');
    $space = $findings->firstWhere('title', 'Double space between sentences');
    $filler = $findings->first(fn ($f) => str_starts_with($f->title, 'Filler word: "really"'));

    expect($space->suggestion)->toBe('dawn. Nurses')
        ->and($space->excerpt)->toBe('dawn.  Nurses')
        ->and($filler->excerpt)->toBe('was really tired')
        ->and($filler->suggestion)->toBe('was tired');

    // Nothing changed until the author approves each one.
    expect($section->fresh()->plainText())->toContain('dawn.  Nurses');

    $this->actingAs($user)->patchJson("/findings/{$space->id}", ['status' => 'accepted'])->assertOk();
    $this->actingAs($user)->patchJson("/findings/{$filler->id}", ['status' => 'accepted'])->assertOk();

    expect($section->fresh()->plainText())
        ->toBe("The clinic opened at dawn. Nurses came early. She was tired by noon.\n\nA second paragraph.");
});

it('does not take a paragraph break for a double space', function () {
    $section = App\Models\ManuscriptSection::factory()->for(Book::factory()->for(User::factory()))->create([
        'content' => jfixDoc('One sentence ends here.', 'Another begins here.'),
    ]);

    expect(jfixReview($section, 'copy')->where('title', 'Double space between sentences'))->toBeEmpty();
});

it('leaves a filler word that cannot simply be deleted as advice', function () {
    $section = App\Models\ManuscriptSection::factory()->for(Book::factory()->for(User::factory()))->create([
        'content' => jfixDoc('Basically, the plan worked.'),
    ]);

    $filler = jfixReview($section, 'copy')->first(fn ($f) => str_starts_with($f->title, 'Filler word: "basically"'));

    expect($filler)->not->toBeNull()
        ->and($filler->suggestion)->toBeNull();
});

// 3. Agents outside the plan.

it('tells the studio which agents the plan includes and which plan unlocks the rest', function () {
    $user = User::factory()->create(['plan' => 'free']);
    $book = Book::factory()->for($user)->create();
    App\Models\ManuscriptSection::factory()->for($book)->create();

    $this->actingAs($user)->get("/books/{$book->id}/edit")
        ->assertInertia(function (Assert $page) {
            $agents = collect($page->toArray()['props']['agents'])->keyBy('value');

            expect($agents['copy']['included'])->toBeTrue()
                ->and($agents['copy']['unlocks'])->toBeNull()
                ->and($agents['fact_checker']['included'])->toBeFalse()
                ->and($agents['fact_checker']['unlocks'])->toBe(config('billing.plans.author.name'))
                ->and($agents['subject_matter']['included'])->toBeFalse();
        });

    $this->actingAs($user)->getJson("/books/{$book->id}/findings")
        ->assertOk()
        ->assertJsonPath('agents.0.included', fn ($v) => is_bool($v));
});

it('includes every agent on the top plan', function () {
    $user = User::factory()->subscribed('studio')->create();
    $book = Book::factory()->for($user)->create();

    $agents = collect(App\Editorial\EditorialAgent::catalogue([], $user));

    expect($agents->every(fn ($a) => $a['included'] === true && $a['unlocks'] === null))->toBeTrue()
        ->and(collect(App\Editorial\EditorialAgent::catalogue())->first())->not->toHaveKey('included');
});

// 4. The storefront shows the description the store listing saved.

it('shows the store listing description on the product, falling back to the old field', function () {
    $user = User::factory()->create();
    $product = partnerProduct($user, rate: null, attributes: ['description' => null]);
    $book = $product->book;

    // Saved the way Export & Publish → Store listing saves it.
    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/publishing", ['description' => 'A harbour town and the clinic that kept it alive.'])
        ->assertOk();

    expect($product->fresh(['book'])->displayDescription())->toBe('A harbour town and the clinic that kept it alive.');

    // The product's own copy still wins.
    $product->forceFill(['description' => 'Product copy.'])->save();
    expect($product->fresh(['book'])->displayDescription())->toBe('Product copy.');

    // A book with only the old description column.
    $product->forceFill(['description' => null])->save();
    $book->forceFill(['meta' => [], 'description' => '<p>Old description.</p>'])->save();
    expect($product->fresh(['book'])->displayDescription())->toBe('Old description.');
});

// 5. Citations reach the prose and the exported book.

function jfixCitedBook(): array
{
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create(['title' => 'The Harbour Clinic', 'language' => 'English (US)']);
    $section = App\Models\ManuscriptSection::factory()->for($book)->create([
        'title' => 'Arrival', 'type' => 'chapter', 'position' => 0,
        'content' => jfixDoc('Rural clinics lost a third of their staff in a decade.'),
    ]);
    $source = App\Models\ResearchSource::factory()->for($book)->verified()->create([
        'authors' => 'Moreau, Claire', 'title' => 'Rural Health in Transition', 'published' => '2021',
        'kind' => 'book', 'publisher' => 'Harbour Press',
    ]);

    return [$user, $book, $section, $source];
}

it('returns the in-text marker for a recorded citation, with its locator', function () {
    [$user, $book, $section, $source] = jfixCitedBook();

    $this->actingAs($user)->postJson("/sections/{$section->id}/citations", [
        'claim' => 'Rural clinics lost a third of their staff in a decade.',
        'excerpt' => 'Rural clinics lost a third of their staff in a decade.',
        'research_source_id' => $source->id,
        'locator' => 'p. 4',
        'status' => 'supported',
    ])->assertCreated()
        ->assertJsonPath('marker.kind', 'text')
        ->assertJsonPath('marker.text', '(Moreau, 2021, p. 4)')
        ->assertJsonPath('citations.0.excerpt', 'Rural clinics lost a third of their staff in a decade.');

    // A claim with no source yet has nothing to put in the text.
    $this->actingAs($user)->postJson("/sections/{$section->id}/citations", [
        'claim' => 'Another claim.', 'status' => 'needs_source',
    ])->assertCreated()->assertJsonPath('marker', null);

    // A notes style gets the note a footnote carries.
    $book->update(['settings' => ['citation_style' => 'Chicago-notes']]);
    $this->actingAs($user)->postJson("/sections/{$section->id}/citations", [
        'claim' => 'Again.', 'research_source_id' => $source->id, 'status' => 'supported',
    ])->assertCreated()->assertJsonPath('marker.kind', 'note');
});

it('ends the PDF, EPUB and Word exports with the cited sources', function () {
    [$user, $book, $section, $source] = jfixCitedBook();
    App\Models\ResearchSource::factory()->for($book)->create(['title' => 'Never Cited Anywhere']);
    $section->citations()->create([
        'claim' => 'Rural clinics lost a third.', 'research_source_id' => $source->id, 'status' => 'supported',
    ]);

    $exporter = app(App\Publishing\Exporter::class);

    $zip = new ZipArchive;
    $zip->open(Illuminate\Support\Facades\Storage::disk('local')->path($exporter->generate($book->fresh(), 'epub')->path));
    $epub = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $epub .= $zip->getFromIndex($i);
    }
    $zip->close();

    expect($epub)->toContain('Bibliography')
        ->toContain('Rural Health in Transition')
        ->not->toContain('Never Cited Anywhere');

    $zip->open(Illuminate\Support\Facades\Storage::disk('local')->path($exporter->generate($book->fresh(), 'docx')->path));
    $docx = $zip->getFromName('word/document.xml');
    $zip->close();

    expect($docx)->toContain('Bibliography')->toContain('Rural Health in Transition');

    $pdf = $exporter->generate($book->fresh(), 'pdf');
    expect($pdf->status)->toBe('ready');

    if (class_exists(Smalot\PdfParser\Parser::class)) {
        $text = (new Smalot\PdfParser\Parser)->parseFile(Illuminate\Support\Facades\Storage::disk('local')->path($pdf->path))->getText();
        expect($text)->toContain('Bibliography');
    }
});

it('leaves exports unchanged for a book with no cited sources, or one with its own bibliography', function () {
    [$user, $book, $section, $source] = jfixCitedBook();
    $exporter = app(App\Publishing\Exporter::class);

    $epubText = function (Book $book) use ($exporter) {
        $zip = new ZipArchive;
        $zip->open(Illuminate\Support\Facades\Storage::disk('local')->path($exporter->generate($book->fresh(), 'epub')->path));
        $all = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $all .= $zip->getFromIndex($i);
        }
        $zip->close();

        return $all;
    };

    // A source in the library but never cited: no list.
    expect($epubText($book))->not->toContain('Rural Health in Transition');

    // Cited, but the author wrote their own bibliography: theirs only.
    $section->citations()->create(['claim' => 'x', 'research_source_id' => $source->id, 'status' => 'supported']);
    App\Models\ManuscriptSection::factory()->for($book)->create([
        'title' => 'Works Cited', 'type' => 'back_matter', 'part' => 'bibliography', 'position' => 10,
        'content' => jfixDoc('My own list.'),
    ]);
    $text = $epubText($book);
    expect($text)->toContain('My own list.')->not->toContain('Rural Health in Transition');

    // And the book's setting can turn the generated list off.
    expect(App\Research\BookReferences::section(tap($book->fresh())->forceFill(['settings' => ['references' => false]])))->toBeNull();
});
