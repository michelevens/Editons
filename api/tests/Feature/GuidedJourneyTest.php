<?php

use App\Models\Book;
use App\Models\User;

/**
 * The Guided Journey interface.
 *
 * The third of the three the directive asks for. These test the claim that
 * makes it worth having: that it is a narrower view of the same book, sharing
 * the manuscript, the stages and the progress with the studio rather than
 * keeping its own idea of any of them.
 */
function guidedBook(User $user, array $attributes = []): Book
{
    return $user->books()->create(array_merge([
        'title' => 'A Guided Book',
        'book_type' => 'nonfiction',
    ], $attributes));
}

it('renders for a book with nothing in it', function () {
    // The state most likely to be broken by assuming chapters exist.
    $user = User::factory()->create();
    $book = guidedBook($user);

    $props = $this->actingAs($user)
        ->get("/books/{$book->id}/guided")
        ->viewData('page')['props'];

    expect($props['book']['title'])->toBe('A Guided Book')
        ->and($props['journey']['stages'])->toHaveCount(6)
        ->and($props['journey']['progress']['chapters'])->toBe(0);
});

it('shows the same journey the API returns', function () {
    // The point of the interface: it presents what exists rather than
    // computing a second opinion that drifts from the studio's.
    $user = User::factory()->create();
    $book = guidedBook($user, ['premise' => 'Something worth saying.']);

    $page = $this->actingAs($user)
        ->get("/books/{$book->id}/guided")
        ->viewData('page')['props']['journey'];

    $api = $this->actingAs($user)
        ->getJson("/books/{$book->id}/journey")
        ->json();

    expect($page)->toEqual($api);
});

it('names one next action and not a list', function () {
    $user = User::factory()->create();
    $book = guidedBook($user);

    $next = $this->actingAs($user)
        ->get("/books/{$book->id}/guided")
        ->viewData('page')['props']['journey']['next'];

    expect($next['label'])->toBe('Say what the book is about')
        ->and($next['stage'])->toBe('discover');
});

it('moves the next action on once the idea exists', function () {
    $user = User::factory()->create();
    $book = guidedBook($user);

    $this->actingAs($user)->patchJson("/books/{$book->id}/idea", [
        'premise' => 'A book about finishing books.',
    ])->assertOk();

    $next = $this->actingAs($user)
        ->get("/books/{$book->id}/guided")
        ->viewData('page')['props']['journey']['next'];

    expect($next['stage'])->toBe('plan');
});

it('marks a stage done from the manuscript, not from a tick', function () {
    // A checklist the author can tick without doing the work is decorative.
    $user = User::factory()->create();
    $book = guidedBook($user);

    $this->actingAs($user)->patchJson("/books/{$book->id}/stage", ['stage' => 'publish']);

    $stages = collect(
        $this->actingAs($user)
            ->get("/books/{$book->id}/guided")
            ->viewData('page')['props']['journey']['stages'],
    )->keyBy('key');

    expect($stages['publish']['current'])->toBeTrue()
        ->and($stages['publish']['done'])->toBeFalse()
        ->and($stages['discover']['done'])->toBeFalse();
});

it('counts the words the studio counts', function () {
    $user = User::factory()->create();
    $book = guidedBook($user);

    $book->sections()->create([
        'type' => 'chapter',
        'title' => 'Written',
        'position' => 100,
        'content' => ['type' => 'doc', 'content' => []],
    ])->forceFill(['word_count' => 420])->save();

    $progress = $this->actingAs($user)
        ->get("/books/{$book->id}/guided")
        ->viewData('page')['props']['journey']['progress'];

    expect($progress['chapters'])->toBe(1)
        ->and($progress['written'])->toBe(1);
});

it('offers the three assistance levels', function () {
    $user = User::factory()->create();
    $book = guidedBook($user);

    $levels = $this->actingAs($user)
        ->get("/books/{$book->id}/guided")
        ->viewData('page')['props']['journey']['assistance_levels'];

    expect(array_column($levels, 'key'))
        ->toBe(['author_led', 'coach', 'collaborative']);
});

it('opens a book begun in Quick Start', function () {
    // All three interfaces, one book. A book started at the simplest door
    // must be openable at this one without conversion.
    $user = User::factory()->create();

    $this->actingAs($user)->post('/books', ['title' => 'Begun Quickly']);
    $book = $user->books()->first();

    $props = $this->actingAs($user)
        ->get("/books/{$book->id}/guided")
        ->viewData('page')['props'];

    expect($props['book']['title'])->toBe('Begun Quickly');
});

it('shows one author nothing of another\'s book', function () {
    $owner = User::factory()->create();
    $book = guidedBook($owner);

    $this->actingAs(User::factory()->create())
        ->get("/books/{$book->id}/guided")
        ->assertForbidden();
});

it('requires signing in', function () {
    $book = guidedBook(User::factory()->create());

    $this->get("/books/{$book->id}/guided")->assertRedirect('/login');
});

it('is reachable from the book, not only by typing the URL', function () {
    // Both Quick Start and this one shipped as routes nothing linked to,
    // which is the same as not shipping them. Asserted against the source
    // because Inertia renders the markup on the client, so the response
    // body carries props rather than buttons.
    $overview = file_get_contents(resource_path('js/Pages/Books/Overview.jsx'));
    $index = file_get_contents(resource_path('js/Pages/Books/Index.jsx'));

    $this->assertStringContainsString(
        "route('journey.guided'", $overview,
        'Overview does not link to the guided journey.',
    );

    $this->assertStringContainsString(
        "route('books.quick')", $index,
        'My Books does not link to Quick Start.',
    );
});
