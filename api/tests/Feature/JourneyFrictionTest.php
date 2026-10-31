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
