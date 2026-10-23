<?php

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Services\WritingProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Writing goals: a chapter's word goal, the daily goal, and the figures the
 * goals sheet shows — today's words and the streak, from version history
 * (the same arithmetic as Analytics), never from a counter of their own.
 */
beforeEach(function () {
    // Midday, so "n days ago" never straddles midnight.
    $this->travelTo(now()->setTime(12, 0));
});

/** A snapshot of a section as it stood $daysAgo days ago. */
function snapshotAt(ManuscriptSection $section, int $words, int $daysAgo): void
{
    $next = (int) DB::table('manuscript_versions')->where('manuscript_section_id', $section->id)->max('version_number') + 1;

    DB::table('manuscript_versions')->insert([
        'manuscript_section_id' => $section->id,
        'version_number' => $next,
        'title' => $section->title,
        'word_count' => $words,
        'reason' => 'autosave',
        'created_at' => now()->subDays($daysAgo),
    ]);
}

/**
 * A chapter written over four days: +0, +150, +150, +120, and +30 today
 * (the live count against yesterday's snapshot).
 */
function fourDaysOfWriting(User $user): ManuscriptSection
{
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create();

    snapshotAt($section, 0, 4);
    snapshotAt($section, 150, 3);
    snapshotAt($section, 300, 2);
    snapshotAt($section, 420, 1);
    $section->forceFill(['word_count' => 450])->save();

    return $section;
}

it('adds a nullable word goal to sections', function () {
    expect(Schema::hasColumn('manuscript_sections', 'word_goal'))->toBeTrue();

    $section = ManuscriptSection::factory()->create();
    expect($section->fresh()->word_goal)->toBeNull();
});

it('sets and clears a chapter word goal', function () {
    $user = User::factory()->create();
    $section = ManuscriptSection::factory()->for(Book::factory()->for($user))->create(['word_count' => 1200]);

    $this->actingAs($user)
        ->patchJson("/sections/{$section->id}/goal", ['goal' => 3000])
        ->assertOk()
        ->assertJson(['id' => $section->id, 'goal' => 3000, 'words' => 1200]);

    expect($section->fresh()->word_goal)->toBe(3000);

    $this->actingAs($user)->patchJson("/sections/{$section->id}/goal", ['goal' => null])->assertOk();

    expect($section->fresh()->word_goal)->toBeNull();
});

it('validates a chapter goal', function (mixed $goal) {
    $user = User::factory()->create();
    $section = ManuscriptSection::factory()->for(Book::factory()->for($user))->create();

    $this->actingAs($user)
        ->patchJson("/sections/{$section->id}/goal", ['goal' => $goal])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('goal');

    expect($section->fresh()->word_goal)->toBeNull();
})->with([
    'zero' => 0,
    'negative' => -5,
    'text' => 'lots',
    'too large' => 1_000_001,
    'fractional' => 12.5,
]);

it('requires the goal field, so a typo cannot silently clear it', function () {
    $user = User::factory()->create();
    $section = ManuscriptSection::factory()->for(Book::factory()->for($user))->create();
    $section->forceFill(['word_goal' => 500])->save();

    $this->actingAs($user)->patchJson("/sections/{$section->id}/goal", ['gaol' => 10])
        ->assertJsonValidationErrors('goal');

    expect($section->fresh()->word_goal)->toBe(500);
});

it('refuses a word goal on a part', function () {
    $user = User::factory()->create();
    $part = ManuscriptSection::factory()->for(Book::factory()->for($user))->create(['type' => 'part']);

    $this->actingAs($user)->patchJson("/sections/{$part->id}/goal", ['goal' => 1000])->assertUnprocessable();

    expect($part->fresh()->word_goal)->toBeNull();
});

it('lets only those who may write in the book set a chapter goal', function () {
    $owner = User::factory()->create();
    $book = Book::factory()->for($owner)->create();
    $section = ManuscriptSection::factory()->for($book)->create();

    $stranger = User::factory()->create();
    $this->actingAs($stranger)->patchJson("/sections/{$section->id}/goal", ['goal' => 100])->assertForbidden();

    $reviewer = User::factory()->create();
    $book->members()->create(['user_id' => $reviewer->id, 'email' => $reviewer->email, 'role' => 'reviewer', 'status' => 'active']);
    $this->actingAs($reviewer)->patchJson("/sections/{$section->id}/goal", ['goal' => 100])->assertForbidden();

    $editor = User::factory()->create();
    $book->members()->create(['user_id' => $editor->id, 'email' => $editor->email, 'role' => 'editor', 'status' => 'active']);
    $this->actingAs($editor)->patchJson("/sections/{$section->id}/goal", ['goal' => 100])->assertOk();

    expect($section->fresh()->word_goal)->toBe(100);
});

it('sets and clears the daily goal within the profile form\'s limits', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patchJson('/profile/daily-goal', ['goal' => 500])
        ->assertOk()
        ->assertJsonPath('today.goal', 500);
    expect($user->fresh()->daily_word_goal)->toBe(500);

    $this->actingAs($user)->patchJson('/profile/daily-goal', ['goal' => 10])->assertJsonValidationErrors('goal');
    $this->actingAs($user)->patchJson('/profile/daily-goal', ['goal' => 20001])->assertJsonValidationErrors('goal');
    expect($user->fresh()->daily_word_goal)->toBe(500);

    $this->actingAs($user)->patchJson('/profile/daily-goal', ['goal' => null])->assertOk()->assertJsonPath('today.goal', null);
    expect($user->fresh()->daily_word_goal)->toBeNull();
});

it('needs a session for the daily goal', function () {
    $this->patchJson('/profile/daily-goal', ['goal' => 500])->assertUnauthorized();
});

it('counts today\'s words the way Analytics does', function () {
    $user = User::factory()->create();
    $section = fourDaysOfWriting($user);

    $progress = WritingProgress::forUser($user);
    $analytics = collect($this->actingAs($user)->getJson("/books/{$section->book_id}/analytics")->json('daily'))
        ->firstWhere('today', true);

    expect($progress['today']['words'])->toBe(30)
        ->and($analytics['words'])->toBe(30)
        ->and($progress['has_history'])->toBeTrue();
});

it('counts a streak of days that met the daily goal, alive until today is over', function () {
    $user = User::factory()->create(['daily_word_goal' => 100]);
    $section = fourDaysOfWriting($user);

    // Today has 30 of 100: the streak runs to yesterday — three days.
    $progress = WritingProgress::forUser($user);
    expect($progress['today'])->toMatchArray(['words' => 30, 'goal' => 100, 'met' => false])
        ->and($progress['streak'])->toMatchArray(['days' => 3, 'rule' => 'goal', 'includes_today' => false]);

    // 140 today: the goal is met, and today joins the streak.
    $section->forceFill(['word_count' => 560])->save();
    $progress = WritingProgress::forUser($user);
    expect($progress['today'])->toMatchArray(['words' => 140, 'met' => true])
        ->and($progress['streak'])->toMatchArray(['days' => 4, 'includes_today' => true]);
});

it('counts any writing toward the streak when there is no daily goal', function () {
    $user = User::factory()->create(['daily_word_goal' => null]);
    fourDaysOfWriting($user);

    $progress = WritingProgress::forUser($user);

    expect($progress['streak'])->toMatchArray(['days' => 4, 'rule' => 'any', 'includes_today' => true])
        ->and($progress['today']['met'])->toBeFalse();
});

it('breaks the streak on a day with no writing', function () {
    $user = User::factory()->create(['daily_word_goal' => 100]);
    $section = ManuscriptSection::factory()->for(Book::factory()->for($user))->create();

    snapshotAt($section, 0, 6);
    snapshotAt($section, 200, 5);   // +200
    // Nothing on days 4 and 3.
    snapshotAt($section, 400, 2);   // +200
    snapshotAt($section, 600, 1);   // +200
    $section->forceFill(['word_count' => 600])->save(); // nothing today

    expect(WritingProgress::forUser($user)['streak']['days'])->toBe(2);
});

it('counts cutting as negative, so a day of cuts does not meet the goal', function () {
    $user = User::factory()->create(['daily_word_goal' => 100]);
    $section = ManuscriptSection::factory()->for(Book::factory()->for($user))->create();

    snapshotAt($section, 1000, 2);
    snapshotAt($section, 400, 1);   // -600 yesterday
    $section->forceFill(['word_count' => 400])->save();

    $progress = WritingProgress::forUser($user);

    expect($progress['streak']['days'])->toBe(0)
        ->and($progress['today']['words'])->toBe(0);
});

it('says there is no history when nothing has been saved', function () {
    $user = User::factory()->create(['daily_word_goal' => 300]);
    $book = Book::factory()->for($user)->create();
    ManuscriptSection::factory()->for($book)->create();

    $data = $this->actingAs($user)->getJson("/books/{$book->id}/goals")->assertOk()->json();

    expect($data['has_history'])->toBeFalse()
        ->and($data['today']['words'])->toBe(0)
        ->and($data['streak']['days'])->toBe(0)
        ->and($data['chapter'])->toBeNull();
});

it('leaves archived books and other people\'s books out of the day', function () {
    $user = User::factory()->create();
    fourDaysOfWriting($user)->book->forceFill(['status' => 'archived'])->save();
    fourDaysOfWriting(User::factory()->create());

    expect(WritingProgress::forUser($user)['today']['words'])->toBe(0);
});

it('serves the goals sheet: chapter, book target, today and streak', function () {
    $user = User::factory()->create(['daily_word_goal' => 100]);
    $section = fourDaysOfWriting($user);
    $section->forceFill(['word_goal' => 2000])->save();
    $section->book->forceFill(['target_words' => 80000, 'word_count' => 450])->save();

    $this->actingAs($user)
        ->getJson("/books/{$section->book_id}/goals?section={$section->id}")
        ->assertOk()
        ->assertJson([
            'chapter' => ['id' => $section->id, 'words' => 450, 'goal' => 2000],
            'book' => ['words' => 450, 'target' => 80000, 'owned' => true],
            'today' => ['words' => 30, 'goal' => 100, 'met' => false],
            'streak' => ['days' => 3, 'rule' => 'goal'],
            'has_history' => true,
        ]);
});

it('keeps the goals sheet inside the book and its readers', function () {
    $owner = User::factory()->create();
    $book = Book::factory()->for($owner)->create();
    $other = ManuscriptSection::factory()->create(); // another book's chapter

    $this->actingAs($owner)->getJson("/books/{$book->id}/goals?section={$other->id}")->assertNotFound();
    $this->actingAs(User::factory()->create())->getJson("/books/{$book->id}/goals")->assertForbidden();
});

it('sends the chapter goal to the studio', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create(['book_type' => 'educational']);
    $section = ManuscriptSection::factory()->for($book)->create();
    $section->forceFill(['word_goal' => 1500])->save();

    $this->actingAs($user)
        ->get("/books/{$book->id}/edit?section={$section->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activeSection.word_goal', 1500)
            ->where('binder.0.word_goal', 1500));
});

it('renames and deletes a chapter from the binder as JSON', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $keep = ManuscriptSection::factory()->for($book)->create(['word_count' => 100]);
    $gone = ManuscriptSection::factory()->for($book)->create(['word_count' => 50]);

    $this->actingAs($user)->patchJson("/sections/{$keep->id}", ['title' => 'Arrival'])
        ->assertOk()->assertJson(['id' => $keep->id, 'title' => 'Arrival']);
    expect($keep->fresh()->title)->toBe('Arrival');

    $this->actingAs($user)->deleteJson("/sections/{$gone->id}")
        ->assertOk()->assertJson(['deleted' => $gone->id, 'book_word_count' => 100]);
    expect(ManuscriptSection::find($gone->id))->toBeNull();

    // Someone else cannot do either.
    $this->actingAs(User::factory()->create())->deleteJson("/sections/{$keep->id}")->assertForbidden();
    $this->actingAs(User::factory()->create())->patchJson("/sections/{$keep->id}", ['title' => 'X'])->assertForbidden();
});
