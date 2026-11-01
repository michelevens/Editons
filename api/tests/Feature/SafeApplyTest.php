<?php

use App\Editorial\ApplyGuard;
use App\Models\Book;
use App\Models\Citation;
use App\Models\EditorialFinding;
use App\Models\EditorialReview;
use App\Models\ManuscriptSection;
use App\Models\User;

/**
 * Safe apply (ADR — Safe apply, honest prices, citation fixes): applying a
 * finding never writes an instruction into the book; a bulk accept leaves
 * out fact-checker fixes and cited quotations, saves one version first and
 * undoes exactly.
 *
 * The evidence: a bulk "Accept 106? 41 fixes" replaced a verified Danticat
 * quotation with "Add a source note identifying where Danticat said or
 * wrote this".
 */
function safeBook(User $owner, array $chapters): Book
{
    $book = Book::factory()->for($owner)->create();
    $position = 10;

    foreach ($chapters as $title => $paragraphs) {
        $book->sections()->create([
            'type' => 'chapter', 'title' => $title, 'position' => $position += 10, 'word_count' => 50,
            'content' => ['type' => 'doc', 'content' => array_map(
                fn ($p) => is_array($p) ? $p : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $p]]],
                $paragraphs,
            )],
        ]);
    }

    return $book->fresh();
}

function safeFinding(ManuscriptSection $section, string $agent, string $excerpt, ?string $suggestion, string $driver = 'anthropic'): EditorialFinding
{
    $review = EditorialReview::create([
        'manuscript_section_id' => $section->id, 'agent' => $agent, 'status' => 'completed', 'driver' => $driver,
    ]);

    return EditorialFinding::create([
        'editorial_review_id' => $review->id, 'manuscript_section_id' => $section->id, 'agent' => $agent,
        'severity' => 'warning', 'title' => 'Finding '.$agent, 'detail' => 'Why it matters.',
        'excerpt' => $excerpt, 'suggestion' => $suggestion,
    ]);
}

const SAFE_QUOTE = 'She said, “I came to this country with very little.”';

// ── The guard ──────────────────────────────────────────────────────────

it('tells replacement text from advice to the author', function (string $excerpt, string $suggestion, bool $advice) {
    expect(ApplyGuard::isAdvice($excerpt, $suggestion))->toBe($advice);
})->with([
    // The walkthrough's corruptions.
    'source note after a colon' => ['“I came to this country with very little.”', 'In her own words: Add a source note identifying where Danticat said or wrote this.', true],
    'cite instruction' => ['He learned the trade from his uncle in Brooklyn.', 'Cite Brother, I’m Dying and contemporaneous reporting for each detail.', true],
    'consider' => ['the largest company in the state', 'Consider dating this claim, as rankings change.', true],
    'verify' => ['in 1993', 'Verify the year against the naturalization record.', true],
    'rephrase' => ['very unique', 'Rephrase to avoid the intensifier.', true],
    'mentions the author' => ['a quiet man', 'a quiet man (the author should say who told her this)', true],
    'placeholder' => ['in the spring', 'in the spring of [year]', true],
    'question' => ['in 1993', 'Is 1993 right?', true],
    'far longer' => ['ran', 'ran across the yard, past the gate and the fence, to the river where the boats were tied up for the night', true],
    'unrelated wording' => ['The factory was closing when he arrived in the small upstate town.', 'Ensure dates match chapter four throughout.', true],
    'french advice' => ['en 1993', 'Vérifiez cette date dans les archives.', true],
    // Real edits.
    'tightening' => ['in order to learn the language', 'to learn the language', false],
    'typo' => ['the the river', 'the river', false],
    'fact fix' => ['a week before her fortieth birthday', 'less than a week after her fortieth birthday', false],
    'same imperative start' => ['Add daily immersion to your routine, every single day.', 'Add daily immersion to your routine.', false],
    'line rewrite' => ['He was a man who was known for being kind.', 'He was known for his kindness.', false],
    'spacing' => ['end.  Then', 'end. Then', false],
]);

it('shows advice as advice: no fix to apply, its text in the detail', function () {
    $user = User::factory()->create();
    $book = safeBook($user, ['One' => [SAFE_QUOTE]]);
    $section = $book->sections()->first();
    $advice = safeFinding($section, 'copy', 'very little', 'Add a source note identifying where she said this.');

    $finding = collect($this->actingAs($user)->getJson("/books/{$book->id}/findings")->assertOk()->json('findings'))->firstWhere('id', $advice->id);
    expect($finding['suggestion'])->toBeNull()
        ->and($finding['detail'])->toBe('Why it matters. Add a source note identifying where she said this.');

    $panel = collect($this->actingAs($user)->getJson("/sections/{$section->id}/findings")->assertOk()->json('findings'))->firstWhere('id', $advice->id);
    expect($panel['suggestion'])->toBeNull()->and($panel['detail'])->toContain('Add a source note');
});

it('never applies an instruction, one by one or in bulk', function () {
    $user = User::factory()->create();
    $book = safeBook($user, ['One' => ['He learned the trade from his uncle in Brooklyn.']]);
    $section = $book->sections()->first();
    $before = $section->content;
    $single = safeFinding($section, 'line', 'from his uncle', 'Cite the memoir for this detail.');
    $bulk = safeFinding($section, 'copy', 'in Brooklyn', 'Add a source note identifying where this was said.');

    $this->actingAs($user)->patchJson("/findings/{$single->id}", ['status' => 'accepted'])->assertStatus(409);
    $this->actingAs($user)->postJson("/books/{$book->id}/findings/bulk", ['ids' => [$bulk->id], 'action' => 'accept', 'confirmed' => true])
        ->assertOk()->assertJsonPath('done', 0)->assertJsonCount(1, 'skipped');

    expect($section->fresh()->content)->toBe($before)
        ->and($single->fresh()->status)->toBe('open')
        ->and($bulk->fresh()->status)->toBe('open');
});

it('leaves Fact Checker and Subject Matter fixes out of a bulk accept, and applies them one by one', function () {
    $user = User::factory()->create();
    $book = safeBook($user, ['One' => ['Ansari flew a week before her fortieth birthday. The plant made yogurt for the region.']]);
    $section = $book->sections()->first();
    $fact = safeFinding($section, 'fact_checker', 'a week before', 'less than a week after');
    $subject = safeFinding($section, 'subject_matter', 'yogurt', 'strained yogurt');
    $copy = safeFinding($section, 'copy', 'for the region', 'for the whole region');

    $response = $this->actingAs($user)->postJson("/books/{$book->id}/findings/bulk", ['ids' => [$fact->id, $subject->id, $copy->id], 'action' => 'accept', 'confirmed' => true])
        ->assertOk()->assertJsonPath('done', 1);
    expect(collect($response->json('skipped'))->pluck('id')->sort()->values()->all())->toBe(collect([$fact->id, $subject->id])->sort()->values()->all())
        ->and($section->fresh()->plainText())->toContain('a week before')->toContain('for the whole region')
        ->and($fact->fresh()->status)->toBe('open');

    // The author applies the fact fix on its own.
    $this->actingAs($user)->patchJson("/findings/{$fact->id}", ['status' => 'accepted'])->assertOk();
    expect($section->fresh()->plainText())->toContain('less than a week after');

    // The listing says which are one by one.
    $listed = collect($this->actingAs($user)->getJson("/books/{$book->id}/findings?status=all")->json('findings'))->keyBy('id');
    expect($listed[$fact->id]['one_by_one'])->toBeTrue()->and($listed[$copy->id]['one_by_one'])->toBeFalse();
});

it('never changes a cited quotation in bulk, but lets the author apply it on its own', function () {
    $user = User::factory()->create();
    $book = safeBook($user, ['One' => [
        // A quotation with a note marker in its paragraph.
        ['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => SAFE_QUOTE],
            ['type' => 'footnote', 'attrs' => ['text' => 'CBS News, 2021.']],
        ]],
        'An uncited “so so” day, he said.',
        'The plant opened in 2005 and grew quickly.',
    ]]);
    $section = $book->sections()->first();
    Citation::create(['manuscript_section_id' => $section->id, 'claim' => 'The plant opened in 2005.', 'excerpt' => 'The plant opened in 2005']);

    $inCited = safeFinding($section, 'copy', 'with very little', 'with little');
    $inQuote = safeFinding($section, 'copy', '“so so” day', '“so-so” day');
    $inCitation = safeFinding($section, 'line', 'grew quickly', 'grew fast');

    $listed = collect($this->actingAs($user)->getJson("/books/{$book->id}/findings")->json('findings'))->keyBy('id');
    expect($listed[$inCited->id]['quote'])->toBe('cited_quote')
        ->and($listed[$inQuote->id]['quote'])->toBe('quote')
        ->and($listed[$inCitation->id]['quote'])->toBe('citation');

    $response = $this->actingAs($user)->postJson("/books/{$book->id}/findings/bulk", ['ids' => [$inCited->id, $inQuote->id, $inCitation->id], 'action' => 'accept', 'confirmed' => true])
        ->assertOk()->assertJsonPath('done', 2);
    expect($response->json('skipped.0.id'))->toBe($inCited->id)
        ->and($section->fresh()->plainText())->toContain('with very little')->toContain('so-so')->toContain('grew fast');

    // Explicitly, on its own: allowed.
    $this->actingAs($user)->patchJson("/findings/{$inCited->id}", ['status' => 'accepted'])->assertOk();
    expect($section->fresh()->plainText())->toContain('with little.”');
});

it('saves one version before a bulk accept and undoes it exactly', function () {
    $user = User::factory()->create();
    $book = safeBook($user, [
        'One' => ['He went in order to learn the language. The the river was wide.'],
        'Two' => ['She was a woman who was known for being kind.'],
    ]);
    [$one, $two] = $book->sections()->orderBy('position')->get();
    $originalOne = $one->content;
    $originalTwo = $two->content;
    $a = safeFinding($one, 'line', 'in order to learn', 'to learn');
    $b = safeFinding($one, 'copy', 'The the river', 'The river');
    $c = safeFinding($two, 'line', 'a woman who was known for being kind', 'known for her kindness');
    $note = safeFinding($two, 'developmental', 'She was', null);

    $response = $this->actingAs($user)->postJson("/books/{$book->id}/findings/bulk", ['ids' => [$a->id, $b->id, $c->id, $note->id], 'action' => 'accept', 'confirmed' => true])
        ->assertOk()->assertJsonPath('done', 4);
    $batch = $response->json('batch');
    expect($batch)->toBeString()
        ->and($one->fresh()->plainText())->toContain('He went to learn the language. The river')
        // One checkpoint per chapter changed, with the content from before.
        ->and($one->versions()->where('label', "Before bulk accept ({$batch})")->count())->toBe(1)
        ->and($two->versions()->where('label', "Before bulk accept ({$batch})")->sole()->content)->toEqual($originalTwo)
        ->and(EditorialFinding::where('bulk_batch', $batch)->count())->toBe(4);

    $this->actingAs($user)->postJson("/books/{$book->id}/findings/bulk/{$batch}/undo")
        ->assertOk()->assertJsonPath('restored', 2)->assertJsonPath('kept', []);

    expect($one->fresh()->content)->toEqual($originalOne)
        ->and($two->fresh()->content)->toEqual($originalTwo)
        ->and(EditorialFinding::whereKey([$a->id, $b->id, $c->id, $note->id])->pluck('status')->unique()->all())->toBe(['open'])
        ->and(EditorialFinding::where('bulk_batch', $batch)->count())->toBe(0);

    // Once undone, there is nothing left to undo.
    $this->actingAs($user)->postJson("/books/{$book->id}/findings/bulk/{$batch}/undo")->assertNotFound();
});

it('does not roll back a chapter written since the bulk accept, and only undoes this book’s batch', function () {
    $user = User::factory()->create();
    $book = safeBook($user, ['One' => ['He went in order to learn the language.']]);
    $section = $book->sections()->first();
    $finding = safeFinding($section, 'line', 'in order to learn', 'to learn');

    $batch = $this->actingAs($user)->postJson("/books/{$book->id}/findings/bulk", ['ids' => [$finding->id], 'action' => 'accept', 'confirmed' => true])->json('batch');

    // Someone else's book cannot undo it.
    $other = User::factory()->create();
    $otherBook = safeBook($other, ['X' => ['Words.']]);
    $this->actingAs($other)->postJson("/books/{$otherBook->id}/findings/bulk/{$batch}/undo")->assertNotFound();
    $this->actingAs($other)->postJson("/books/{$book->id}/findings/bulk/{$batch}/undo")->assertForbidden();

    $this->travel(5)->seconds();
    $this->actingAs($user)->putJson("/sections/{$section->id}/content", ['content' => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'He went to learn the language. And more.']]],
    ]]])->assertSuccessful();

    $this->actingAs($user)->postJson("/books/{$book->id}/findings/bulk/{$batch}/undo")
        ->assertOk()->assertJsonPath('restored', 0)->assertJsonPath('kept.0.id', $section->id);
    expect($section->fresh()->plainText())->toContain('And more.');
});
