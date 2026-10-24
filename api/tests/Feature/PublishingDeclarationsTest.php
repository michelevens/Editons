<?php

use App\Models\Book;
use App\Models\EditorialFinding;
use App\Models\EditorialReview;
use App\Models\ManuscriptSection;
use App\Models\ManuscriptVersion;
use App\Models\User;
use App\Publishing\AiDisclosure;
use App\Publishing\IsbnPlan;
use App\Publishing\LegalDeposit;
use App\Publishing\PrintCheck;
use App\Publishing\StoreReadiness;
use App\Publishing\SubmissionCheck;

/**
 * What an author states before the stores: the ISBN source per edition,
 * the rights declaration, the AI-content answers and the legal-deposit
 * reminder; and the print defaults a new book starts with.
 */
function readyBook(?User $owner = null, array $attributes = []): Book
{
    $owner ??= User::factory()->create();
    $book = $owner->books()->create(array_merge([
        'title' => 'Lakou',
        'author_name' => 'A Writer',
        'list_price' => 4.99,
        'meta' => [
            'description' => str_repeat('A clear and useful description of the book. ', 4),
            'category' => 'Fiction / Literary',
        ],
    ], $attributes));

    ManuscriptSection::factory()->for($book)->create([
        'type' => 'chapter', 'title' => 'One', 'position' => 0,
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Prose.']]]]],
    ]);
    $book->forceFill(['word_count' => 20_000])->save();

    return $book->fresh();
}

/* ---------------------------------------------------------- ISBN per format */

it('records an ISBN source per edition and checks it against the stores', function () {
    $user = User::factory()->create();
    $book = readyBook($user);

    $this->actingAs($user)->patchJson("/books/{$book->id}/rights/isbn-plan", ['edition' => 'paperback', 'source' => 'store'])
        ->assertOk()
        ->assertJsonPath('isbn_plan.paperback.source', 'store')
        ->assertJsonPath('isbn_plan.ebook.source', null);
    $this->actingAs($user)->patchJson("/books/{$book->id}/rights/isbn-plan", ['edition' => 'audiobook', 'source' => 'store'])->assertStatus(422);

    // One paperback at two print stores cannot carry a store's ISBN.
    $book->channels()->create(['channel' => 'kdp_paperback', 'format' => 'print']);
    $book->channels()->create(['channel' => 'ingram_print', 'format' => 'print']);
    $isbn = collect(app(PrintCheck::class)->for($book->fresh(), 'kdp')['items'])->firstWhere('key', 'isbn');
    expect($isbn['status'])->toBe('fail')->and($isbn['message'])->toContain('can only be used on KDP');

    // Own chosen, none assigned: fails too.
    $this->actingAs($user)->patchJson("/books/{$book->id}/rights/isbn-plan", ['edition' => 'paperback', 'source' => 'own'])->assertOk();
    expect(collect(app(PrintCheck::class)->for($book->fresh(), 'ingram')['items'])->firstWhere('key', 'isbn')['status'])->toBe('fail');

    // A valid own number passes; a bad checksum is refused, never stored.
    $this->actingAs($user)->postJson("/books/{$book->id}/isbns", ['edition_format' => 'paperback', 'isbn' => '9780306406158'])->assertStatus(422);
    $this->actingAs($user)->postJson("/books/{$book->id}/isbns", ['edition_format' => 'paperback', 'isbn' => '978-0-306-40615-7'])->assertOk();
    expect(IsbnPlan::plan($book->fresh())['paperback'])->toBe(['source' => 'own', 'isbn' => '9780306406157'])
        ->and(collect(app(PrintCheck::class)->for($book->fresh(), 'ingram')['items'])->firstWhere('key', 'isbn')['status'])->toBe('pass');
});

it('tells each ebook store what the ebook ISBN choice means', function () {
    $book = readyBook();
    $item = fn (string $channel) => collect(app(StoreReadiness::class)->for($book->fresh(), $channel, true)['items'])->firstWhere('key', 'isbn');

    expect($item('amazon_kdp')['status'])->toBe('pass')
        ->and($item('google_play')['message'])->toContain('assigns its own identifier')
        ->and($item('kobo')['status'])->toBe('warn');

    $book->forceFill(['isbn_plan' => ['ebook' => 'own']])->save();
    expect($item('apple_books')['status'])->toBe('fail');
});

it('points to the ISBN agency for the country of publication', function () {
    $book = readyBook(attributes: ['publication' => ['country' => 'CA']]);
    expect(IsbnPlan::agency($book))->toMatchArray(['country' => 'CA', 'free' => true])
        ->and(IsbnPlan::agency($book)['url'])->toContain('canada.ca');

    $haiti = readyBook(attributes: ['publication' => ['country' => 'HT']]);
    expect(IsbnPlan::agency($haiti)['url'])->toBe('https://www.isbn-international.org/agencies');

    expect(IsbnPlan::agency(readyBook())['country'])->toBeNull();
});

/* ------------------------------------------------------------ print defaults */

it('opens new books at 6 x 9 on white, black ink, matte, and textbooks at letter', function () {
    $user = User::factory()->subscribed('author')->create();

    $this->actingAs($user)->post('/books', ['title' => 'A Novel'])->assertRedirect();
    $novel = $user->books()->where('title', 'A Novel')->first();
    expect($novel->layout()['trim'])->toBe('6 x 9 in')
        ->and($novel->print)->toEqual(['paper' => 'white', 'ink' => 'bw', 'finish' => 'matte']);

    $this->actingAs($user)->post('/books', ['title' => 'Physique 9e', 'book_type' => 'educational'])->assertRedirect();
    expect($user->books()->where('title', 'Physique 9e')->first()->layout()['trim'])->toBe('8.5 x 11 in');
});

it('makes workbook editions at letter size', function () {
    $user = User::factory()->subscribed('author')->create();
    $book = readyBook($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/editions", ['type' => 'workbook'])->assertSuccessful();

    expect(Book::where('parent_book_id', $book->id)->where('edition_type', 'workbook')->first()->layout()['trim'])->toBe('8.5 x 11 in');
});

/* -------------------------------------------------------- rights declaration */

it('requires a confirmed rights declaration, recording who and when', function () {
    $user = User::factory()->create(['name' => 'Marie Joseph']);
    $book = readyBook($user);
    $book->channels()->create(['channel' => 'apple_books', 'format' => 'ebook']);

    expect(app(SubmissionCheck::class)->errors($book))->toHaveKey('rights_declaration');

    // Not without saying so.
    $this->actingAs($user)->putJson("/books/{$book->id}/rights/declaration", ['materials' => []])->assertStatus(422);

    $this->actingAs($user)->putJson("/books/{$book->id}/rights/declaration", [
        'holds_rights' => true,
        'materials' => [
            ['kind' => 'official', 'description' => 'Exam paper, 2019, Ministry of Education', 'permission' => 'unsure'],
            ['kind' => 'image', 'description' => 'Map, page 12', 'permission' => 'licence'],
        ],
        // Who confirms is the session, never the request.
        'confirmed_by' => 999,
    ])->assertOk()
        ->assertJsonPath('declaration.confirmed', true)
        ->assertJsonPath('declaration.confirmed_by', 'Marie Joseph');

    $book->refresh();
    expect($book->rights_declaration['confirmed_by'])->toBe($user->id)
        ->and($book->rights_declaration['materials'])->toHaveCount(2)
        ->and(app(SubmissionCheck::class)->errors($book))->not->toHaveKey('rights_declaration')
        ->and(app(SubmissionCheck::class)->warnings($book))->toHaveKey('rights_unsure');

    $this->actingAs($user)->putJson("/books/{$book->id}/rights/declaration", [
        'holds_rights' => true,
        'materials' => [['kind' => 'quote', 'description' => 'x', 'permission' => 'stolen']],
    ])->assertStatus(422);

    $this->actingAs(User::factory()->create())->putJson("/books/{$book->id}/rights/declaration", ['holds_rights' => true, 'materials' => []])->assertForbidden();
});

/* ------------------------------------------------------------- AI disclosure */

it('suggests AI answers from the book\'s provenance and records the author\'s own', function () {
    $user = User::factory()->create();
    $book = readyBook($user);
    $book->channels()->create(['channel' => 'amazon_kdp', 'format' => 'ebook']);
    $section = $book->sections()->first();

    // Nothing recorded: nothing suggested.
    expect(AiDisclosure::for($book)['suggested'])->toBe(['text' => 'none', 'images' => 'none', 'translations' => 'none'])
        ->and(app(SubmissionCheck::class)->errors($book))->toHaveKey('ai_disclosure');

    // An accepted AI fix is AI-assisted.
    $version = ManuscriptVersion::create(['manuscript_section_id' => $section->id, 'version_number' => 50, 'title' => 'One', 'content' => $section->content, 'word_count' => 1, 'reason' => 'ai_suggestion']);
    $review = EditorialReview::create(['manuscript_section_id' => $section->id, 'agent' => 'copy', 'status' => 'completed', 'driver' => 'fake']);
    EditorialFinding::create([
        'editorial_review_id' => $review->id, 'manuscript_section_id' => $section->id, 'agent' => 'copy',
        'severity' => 'suggestion', 'title' => 'Tighten', 'detail' => 'd', 'status' => 'accepted', 'applied_version_id' => $version->id,
    ]);
    expect(AiDisclosure::for($book->fresh())['suggested']['text'])->toBe('assisted');

    // A chapter drafted by AI is AI-generated; so is an AI cover.
    ManuscriptVersion::create(['manuscript_section_id' => $section->id, 'version_number' => 51, 'title' => 'One', 'content' => $section->content, 'word_count' => 1, 'reason' => 'ai_draft']);
    $book->update(['cover' => ['artwork' => 'covers/x.jpg', 'artwork_source' => ['type' => 'ai', 'model' => 'image-model-1']]]);
    $ai = AiDisclosure::for($book->fresh());
    expect($ai['suggested'])->toBe(['text' => 'generated', 'images' => 'generated', 'translations' => 'none'])
        ->and($ai['evidence']['images']['ai_cover']['model'])->toBe('image-model-1')
        // Never answered for the author.
        ->and($ai['answers'])->toBeNull();

    // The author answers, and says so.
    $this->actingAs($user)->putJson("/books/{$book->id}/rights/ai-disclosure", ['answers' => ['text' => 'none', 'images' => 'none', 'translations' => 'none']])->assertStatus(422);
    $this->actingAs($user)->putJson("/books/{$book->id}/rights/ai-disclosure", [
        'confirm' => true,
        'answers' => ['text' => 'assisted', 'images' => 'generated', 'translations' => 'none'],
    ])->assertOk()
        ->assertJsonPath('ai.answers.text', 'assisted')
        ->assertJsonPath('ai.kdp.images', true)
        ->assertJsonPath('ai.kdp.text', false)
        ->assertJsonPath('ai.outdated', ['text']);

    $book->refresh();
    expect($book->ai_disclosure['confirmed_by'])->toBe($user->id)
        ->and($book->ai_disclosure['evidence']['text']['ai_drafts'])->toBe(1)
        ->and(app(SubmissionCheck::class)->errors($book))->not->toHaveKey('ai_disclosure')
        ->and(app(SubmissionCheck::class)->warnings($book))->toHaveKey('ai_disclosure');
});

it('asks the AI questions only when a KDP store is chosen', function () {
    $book = readyBook();
    $book->channels()->create(['channel' => 'kobo', 'format' => 'ebook']);

    expect(app(SubmissionCheck::class)->errors($book))->not->toHaveKey('ai_disclosure');
});

/* -------------------------------------------------------------- legal deposit */

it('reminds of legal deposit by country, from its source, and generically otherwise', function () {
    $haiti = readyBook(attributes: ['publication' => ['country' => 'HT']]);
    $deposit = LegalDeposit::for($haiti);

    expect($deposit['message'])->toContain('5 copies')
        ->and($deposit['message'])->toContain('Bibliothèque nationale d\'Haïti')
        ->and($deposit['source'])->toContain('lenouvelliste.com');

    expect(LegalDeposit::for(readyBook(attributes: ['publication' => ['country' => 'US']]))['message'])->toContain('2 copies')
        ->and(LegalDeposit::for(readyBook())['source'])->toBeNull()
        ->and(LegalDeposit::for(readyBook(attributes: ['publication' => ['country' => 'JM']]))['message'])->toContain('Check the rule');

    $haiti->channels()->create(['channel' => 'kdp_paperback', 'format' => 'print']);
    expect(app(SubmissionCheck::class)->warnings($haiti->fresh()))->toHaveKey('legal_deposit');
});

it('saves the country of publication from the settings form', function () {
    $user = User::factory()->create();
    $book = readyBook($user);

    $this->actingAs($user)->patch("/books/{$book->id}", ['publication' => ['country' => 'HT']])->assertSessionHasNoErrors();
    expect($book->fresh()->publication()['country'])->toBe('HT');

    $this->actingAs($user)->patch("/books/{$book->id}", ['publication' => ['country' => 'Haiti']])->assertSessionHasErrors('publication.country');
});

it('shows the declarations on ISBN & Rights', function () {
    $user = User::factory()->create();
    $book = readyBook($user, ['publication' => ['country' => 'FR']]);

    $this->actingAs($user)->getJson("/books/{$book->id}/rights")
        ->assertOk()
        ->assertJsonPath('isbn_agency.agency', 'AFNIL')
        ->assertJsonPath('declaration.confirmed', false)
        ->assertJsonPath('ai.answers', null)
        ->assertJsonPath('legal_deposit.source', 'https://www.bnf.fr/node/255');
});
