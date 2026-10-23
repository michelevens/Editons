<?php

use App\Models\Book;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * The Launch Planner and the Sales screen.
 */
function launchBook(User $user, array $attributes = []): Book
{
    return $user->books()->create(array_merge(['title' => 'A Book'], $attributes));
}

it('returns the timeline with no date set', function () {
    // Before a date is chosen the tasks still exist; they simply have no due
    // dates yet. An empty screen would hide what the work actually is.
    $user = User::factory()->create();
    $book = launchBook($user);

    $response = $this->actingAs($user)
        ->getJson("/books/{$book->id}/launch")
        ->assertOk();

    expect($response->json('launch_date'))->toBeNull()
        ->and($response->json('tasks'))->toHaveCount(8)
        ->and($response->json('tasks.0.due'))->toBeNull()
        ->and($response->json('tasks.0.overdue'))->toBeFalse();
});

it('dates every task from the launch date', function () {
    // The timeline is computed, so moving the date moves everything with it.
    $user = User::factory()->create();
    $book = launchBook($user);

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/launch", ['launch_date' => now()->addDays(90)->toDateString()])
        ->assertOk();

    $tasks = collect($this->actingAs($user)->getJson("/books/{$book->id}/launch")->json('tasks'));

    expect($tasks->firstWhere('key', 'recruit_readers')['due'])
        ->toBe(now()->addDays(30)->toFormattedDateString())
        ->and($tasks->firstWhere('key', 'publish')['due'])
        ->toBe(now()->addDays(90)->toFormattedDateString());
});

it('reports how far away the launch is', function () {
    $user = User::factory()->create();
    $book = launchBook($user, ['launch_date' => now()->addDays(45)->toDateString()]);

    expect($this->actingAs($user)->getJson("/books/{$book->id}/launch")->json('days_away'))
        ->toBe(45);
});

it('reports a launch in the past as negative', function () {
    $user = User::factory()->create();
    $book = launchBook($user, ['launch_date' => now()->subDays(10)->toDateString()]);

    expect($this->actingAs($user)->getJson("/books/{$book->id}/launch")->json('days_away'))
        ->toBe(-10);
});

it('flags a task whose date has passed', function () {
    $user = User::factory()->create();
    // Launch was a week ago, so everything before launch day is overdue.
    $book = launchBook($user, ['launch_date' => now()->subDays(7)->toDateString()]);

    $tasks = collect($this->actingAs($user)->getJson("/books/{$book->id}/launch")->json('tasks'));

    expect($tasks->firstWhere('key', 'recruit_readers')['overdue'])->toBeTrue()
        ->and($tasks->firstWhere('key', 'run_promotion')['overdue'])->toBeFalse();
});

it('does not call a finished task overdue', function () {
    $user = User::factory()->create();
    $book = launchBook($user, ['launch_date' => now()->subDays(7)->toDateString()]);

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/launch", ['task' => 'recruit_readers', 'done' => true])
        ->assertOk();

    $tasks = collect($this->actingAs($user)->getJson("/books/{$book->id}/launch")->json('tasks'));

    expect($tasks->firstWhere('key', 'recruit_readers')['overdue'])->toBeFalse()
        ->and($tasks->firstWhere('key', 'recruit_readers')['done'])->toBeTrue();
});

it('counts what is done', function () {
    $user = User::factory()->create();
    $book = launchBook($user);

    foreach (['recruit_readers', 'send_copies'] as $task) {
        $this->actingAs($user)
            ->patchJson("/books/{$book->id}/launch", ['task' => $task, 'done' => true]);
    }

    $response = $this->actingAs($user)->getJson("/books/{$book->id}/launch");

    expect($response->json('done_count'))->toBe(2)
        ->and($response->json('task_count'))->toBe(8);
});

it('rejects a task key that is not on the timeline', function () {
    $user = User::factory()->create();
    $book = launchBook($user);

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/launch", ['task' => 'rm_rf', 'done' => true])
        ->assertStatus(422);
});

// --- Advance readers ----------------------------------------------------

it('adds an advance reader', function () {
    $user = User::factory()->create();
    $book = launchBook($user);

    $this->actingAs($user)
        ->postJson("/books/{$book->id}/readers", [
            'name' => 'A Reader',
            'email' => 'reader@example.com',
        ])
        ->assertCreated();

    expect($book->advanceReaders()->count())->toBe(1)
        ->and($book->advanceReaders()->first()->status)->toBe('invited');
});

it('will not add the same reader twice', function () {
    // A double submit would email somebody twice and show them twice.
    $user = User::factory()->create();
    $book = launchBook($user);

    $this->actingAs($user)->postJson("/books/{$book->id}/readers", [
        'name' => 'A Reader', 'email' => 'reader@example.com',
    ])->assertCreated();

    $this->actingAs($user)->postJson("/books/{$book->id}/readers", [
        'name' => 'Same Person', 'email' => 'reader@example.com',
    ])->assertStatus(422);

    expect($book->advanceReaders()->count())->toBe(1);
});

it('records when a copy was sent, from the server', function () {
    // When a copy went out is a record of what happened, not something a
    // client should be able to claim.
    $user = User::factory()->create();
    $book = launchBook($user);
    $reader = $book->advanceReaders()->create(['name' => 'R', 'email' => 'r@example.com']);

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/readers/{$reader->id}", ['status' => 'sent'])
        ->assertOk();

    expect($reader->fresh()->copy_sent_at)->not->toBeNull();
});

it('fills in the copy date when a reader goes straight to reviewed', function () {
    // The database refuses a review with no copy behind it, so the jump is
    // completed rather than rejected.
    $user = User::factory()->create();
    $book = launchBook($user);
    $reader = $book->advanceReaders()->create(['name' => 'R', 'email' => 'r@example.com']);

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/readers/{$reader->id}", ['status' => 'reviewed'])
        ->assertOk();

    expect($reader->fresh()->reviewed_at)->not->toBeNull()
        ->and($reader->fresh()->copy_sent_at)->not->toBeNull();
});

it('refuses a review with no copy behind it at the database', function () {
    $user = User::factory()->create();
    $book = launchBook($user);

    // forceFill, because reviewed_at is deliberately not fillable — mass
    // assignment alone could never reach this state. The constraint is the
    // backstop for code that bypasses the model's guard.
    expect(fn () => $book->advanceReaders()->make([
        'name' => 'R',
        'email' => 'r@example.com',
        'status' => 'reviewed',
    ])->forceFill(['reviewed_at' => now()])->save())
        ->toThrow(QueryException::class);
});

it('counts reviews', function () {
    $user = User::factory()->create();
    $book = launchBook($user);

    foreach (['a', 'b', 'c'] as $name) {
        $book->advanceReaders()->create(['name' => $name, 'email' => "{$name}@example.com"]);
    }

    $first = $book->advanceReaders()->first();

    $this->actingAs($user)
        ->patchJson("/books/{$book->id}/readers/{$first->id}", ['status' => 'reviewed']);

    expect($this->actingAs($user)->getJson("/books/{$book->id}/launch")->json('reviews'))
        ->toBe(1);
});

it('removes a reader', function () {
    $user = User::factory()->create();
    $book = launchBook($user);
    $reader = $book->advanceReaders()->create(['name' => 'R', 'email' => 'r@example.com']);

    $this->actingAs($user)
        ->deleteJson("/books/{$book->id}/readers/{$reader->id}")
        ->assertOk();

    expect($book->advanceReaders()->count())->toBe(0);
});

it('will not touch a reader belonging to another book', function () {
    $user = User::factory()->create();
    $first = launchBook($user);
    $second = launchBook($user, ['title' => 'Another']);

    $reader = $first->advanceReaders()->create(['name' => 'R', 'email' => 'r@example.com']);

    $this->actingAs($user)
        ->deleteJson("/books/{$second->id}/readers/{$reader->id}")
        ->assertNotFound();

    expect($reader->fresh())->not->toBeNull();
});

it('stops a stranger planning somebody else\'s launch', function () {
    $book = launchBook(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->patchJson("/books/{$book->id}/launch", ['launch_date' => now()->toDateString()])
        ->assertForbidden();
});

// --- Sales & royalties --------------------------------------------------

it('says plainly that no store has the book yet', function () {
    // Rather than a grid of zeroes, which reads like a broken screen.
    $user = User::factory()->create();
    $book = launchBook($user);

    $response = $this->actingAs($user)
        ->getJson("/books/{$book->id}/sales")
        ->assertOk();

    expect($response->json('reporting.connected'))->toBeFalse()
        ->and($response->json('reporting.message'))->toContain('No store')
        ->and($response->json('channels'))->toBe([]);
});

it('reports what each store would pay per copy', function () {
    // Arithmetic on a published rate, which is knowable — unlike sales.
    $user = User::factory()->create();
    $book = launchBook($user, ['list_price' => 20.00]);
    $book->channels()->create(['channel' => 'amazon_kdp', 'format' => 'ebook']);

    $channel = collect(
        $this->actingAs($user)->getJson("/books/{$book->id}/sales")->json('channels')
    )->firstWhere('channel', 'amazon_kdp');

    // $20 is above Kindle's 70% band ($2.99-$12.99 since July 2026,
    // config/publishing.php), so the 35% option applies: $7.00, not $14.
    expect($channel['rate'])->toBe(0.35)
        ->and((float) $channel['per_copy'])->toBe(7.0)
        ->and($channel['note'])->toContain('70%');
});

it('reports no per-copy figure without a price', function () {
    // A royalty of zero and an unknown royalty are different things.
    $user = User::factory()->create();
    $book = launchBook($user);
    $book->channels()->create(['channel' => 'kobo', 'format' => 'ebook']);

    $channel = collect(
        $this->actingAs($user)->getJson("/books/{$book->id}/sales")->json('channels')
    )->first();

    expect($channel['per_copy'])->toBeNull();
});

it('never invents units or earnings', function () {
    // The prototype fills both from a seeded generator and tags it "sample
    // data". A number on a royalties screen is one an author will believe.
    $user = User::factory()->create();
    $book = launchBook($user, ['list_price' => 20.00]);

    $channel = $book->channels()->create(['channel' => 'kobo', 'format' => 'ebook']);
    $channel->forceFill([
        'status' => 'live',
        'submitted_at' => now()->subMonth(),
        'went_live_at' => now()->subWeeks(3),
    ])->save();

    $row = collect(
        $this->actingAs($user)->getJson("/books/{$book->id}/sales")->json('channels')
    )->first();

    expect($row['units'])->toBe(0)
        ->and($row['earnings'])->toBe(0)
        ->and($row['status'])->toBe('live');
});
