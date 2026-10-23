<?php

use App\Editorial\SuggestionApplier;
use App\Models\Book;
use App\Models\EditorialFinding;
use App\Models\EditorialReview;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Services\ManuscriptWriter;

/**
 * Applying a suggestion rewrites somebody's manuscript, so these tests are
 * mostly about when it must refuse.
 */
function sectionWithText(string ...$paragraphs): ManuscriptSection
{
    return ManuscriptSection::factory()
        ->for(Book::factory()->for(User::factory()))
        ->create([
            'content' => ['type' => 'doc', 'content' => array_map(
                fn ($text) => [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => $text]],
                ],
                $paragraphs,
            )],
        ]);
}

function findingOn(ManuscriptSection $section, ?string $excerpt, ?string $suggestion): EditorialFinding
{
    $review = EditorialReview::create([
        'manuscript_section_id' => $section->id,
        'agent' => 'copy',
        'status' => 'completed',
        'driver' => 'fake',
    ]);

    return EditorialFinding::create([
        'editorial_review_id' => $review->id,
        'manuscript_section_id' => $section->id,
        'agent' => 'copy',
        'severity' => 'warning',
        'title' => 'Repeated word',
        'excerpt' => $excerpt,
        'suggestion' => $suggestion,
    ]);
}

it('replaces the passage in the manuscript', function () {
    // The whole point. Before this, accepting marked the finding done and
    // left the prose exactly as it was.
    $section = sectionWithText('The the decision was made early.');
    $finding = findingOn($section, 'The the', 'The');

    app(SuggestionApplier::class)->apply($finding);

    expect($section->fresh()->plainText())->toBe('The decision was made early.');
});

it('leaves the rest of the document alone', function () {
    $section = sectionWithText('First paragraph.', 'The the second one.', 'Third paragraph.');
    $finding = findingOn($section, 'The the', 'The');

    app(SuggestionApplier::class)->apply($finding);

    $text = $section->fresh()->plainText();

    expect($text)->toContain('First paragraph.')
        ->and($text)->toContain('The second one.')
        ->and($text)->toContain('Third paragraph.');
});

it('keeps marks and structure intact', function () {
    // Replacing inside the JSON rather than over serialised HTML is what
    // stops a bold span or a heading being eaten by the edit.
    $section = ManuscriptSection::factory()
        ->for(Book::factory()->for(User::factory()))
        ->create(['content' => ['type' => 'doc', 'content' => [
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [
                ['type' => 'text', 'text' => 'A heading'],
            ]],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'Plain then '],
                ['type' => 'text', 'text' => 'bold text', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => ' and the the end.'],
            ]],
        ]]]);

    app(SuggestionApplier::class)->apply(findingOn($section, 'the the', 'the'));

    $content = $section->fresh()->content;

    expect($content['content'][0]['type'])->toBe('heading')
        ->and($content['content'][1]['content'][1]['marks'][0]['type'])->toBe('bold')
        ->and($content['content'][1]['content'][1]['text'])->toBe('bold text')
        ->and($content['content'][1]['content'][2]['text'])->toBe(' and the end.');
});

it('refuses when the passage is gone', function () {
    // The author edited it away after the review ran. Rewriting something
    // else would be worse than refusing.
    $section = sectionWithText('Completely different wording now.');
    $finding = findingOn($section, 'The the', 'The');

    expect(fn () => app(SuggestionApplier::class)->apply($finding))
        ->toThrow(RuntimeException::class, 'no longer in the chapter');
});

it('refuses when the passage appears more than once', function () {
    // Two identical passages and one suggestion: the application cannot know
    // which was meant, and guessing rewrites the wrong sentence.
    // Both occurrences identical, including case — matching is deliberately
    // case-sensitive, since replacing "The the" must not touch a lowercase one.
    $section = sectionWithText('The the start.', 'The the middle.');
    $finding = findingOn($section, 'The the', 'The');

    expect(fn () => app(SuggestionApplier::class)->apply($finding))
        ->toThrow(RuntimeException::class, 'more than once');
});

it('refuses a finding that offers no replacement', function () {
    $section = sectionWithText('Some prose here.');
    $finding = findingOn($section, 'Some prose', null);

    expect(fn () => app(SuggestionApplier::class)->apply($finding))
        ->toThrow(RuntimeException::class, 'no replacement');
});

it('versions the edit so it can be undone', function () {
    // An AI edit travels through the same versioned write as anything typed,
    // which is what makes accepting one safe.
    $section = sectionWithText('The the decision.');
    $finding = findingOn($section, 'The the', 'The');

    app(SuggestionApplier::class)->apply($finding);

    $version = $section->versions()->latest('version_number')->first();

    expect($version)->not->toBeNull()
        ->and($version->label)->toContain('Applied')
        ->and(json_encode($version->content))->toContain('The the');
});

// --- Over HTTP ------------------------------------------------------------

it('applies through the resolve endpoint and returns the new content', function () {
    // The client needs the rewritten chapter back, or the editor goes on
    // showing the text as it was before the fix.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create([
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'The the decision.']],
        ]]],
    ]);

    $finding = findingOn($section, 'The the', 'The');

    $response = $this->actingAs($user)
        ->patchJson("/findings/{$finding->id}", ['status' => 'accepted'])
        ->assertOk();

    expect($response->json('applied'))->toBeTrue()
        ->and(json_encode($response->json('content')))->toContain('The decision')
        ->and($section->fresh()->plainText())->toBe('The decision.');
});

it('leaves the finding open when the passage has moved', function () {
    // Closing it would tell the author a fix was applied that was not.
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create([
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Rewritten entirely.']],
        ]]],
    ]);

    $finding = findingOn($section, 'The the', 'The');

    $this->actingAs($user)
        ->patchJson("/findings/{$finding->id}", ['status' => 'accepted'])
        ->assertStatus(409);

    expect($finding->refresh()->status)->toBe('open');
});

it('dismisses without touching the manuscript', function () {
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create([
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'The the decision.']],
        ]]],
    ]);

    $finding = findingOn($section, 'The the', 'The');

    $this->actingAs($user)
        ->patchJson("/findings/{$finding->id}", ['status' => 'dismissed'])
        ->assertOk();

    expect($section->fresh()->plainText())->toBe('The the decision.')
        ->and($finding->refresh()->status)->toBe('dismissed');
});

it('records an accepted suggestion as AI-written, not as a manual edit', function () {
    // The distinction an AI-use declaration depends on. Stored as `manual`,
    // an AI-authored sentence is indistinguishable from one the author typed,
    // and no later work recovers which was which.
    $section = sectionWithText('The the decision was made early.');
    $finding = findingOn($section, 'The the', 'The');

    app(SuggestionApplier::class)->apply($finding);

    expect($section->versions()->latest('version_number')->first()->reason)
        ->toBe('ai_suggestion');
});

it('keeps a typed edit marked as the author\'s own', function () {
    // The other half of the same guarantee: marking everything ai_ would be
    // as useless as marking nothing.
    $section = sectionWithText('Something the author wrote.');

    app(ManuscriptWriter::class)->save(
        section: $section,
        content: ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Something the author then changed.']],
        ]]],
        reason: 'manual',
    );

    expect($section->versions()->latest('version_number')->first()->reason)->toBe('manual');
});

// --- Status transitions: an applied fix leaves only through a real undo ---

function ownedFinding(string $text, string $excerpt, string $suggestion): array
{
    $user = User::factory()->create();
    $book = Book::factory()->for($user)->create();
    $section = ManuscriptSection::factory()->for($book)->create([
        'content' => ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => $text]],
        ]]],
    ]);

    return [$user, $section, findingOn($section, $excerpt, $suggestion)];
}

it('refuses to dismiss a fix that is already in the manuscript', function () {
    [$user, $section, $finding] = ownedFinding('The the decision.', 'The the', 'The');

    $this->actingAs($user)->patchJson("/findings/{$finding->id}", ['status' => 'accepted'])->assertOk();

    $this->actingAs($user)->patchJson("/findings/{$finding->id}", ['status' => 'dismissed'])
        ->assertStatus(409);

    expect($finding->refresh()->status)->toBe('accepted')
        ->and($section->fresh()->plainText())->toBe('The decision.');
});

it('refuses to accept a dismissed finding without reopening it', function () {
    [$user, $section, $finding] = ownedFinding('The the decision.', 'The the', 'The');

    $this->actingAs($user)->patchJson("/findings/{$finding->id}", ['status' => 'dismissed'])->assertOk();
    $this->actingAs($user)->patchJson("/findings/{$finding->id}", ['status' => 'accepted'])->assertStatus(409);

    expect($section->fresh()->plainText())->toBe('The the decision.');
});

it('undoes an applied fix exactly when nothing was written since', function () {
    [$user, $section, $finding] = ownedFinding('The the decision.', 'The the', 'The');

    $this->actingAs($user)->patchJson("/findings/{$finding->id}", ['status' => 'accepted'])->assertOk();
    expect($finding->refresh()->applied_version_id)->not->toBeNull();

    $this->actingAs($user)->patchJson("/findings/{$finding->id}/reopen")
        ->assertOk()
        ->assertJsonPath('undone', true);

    expect($section->fresh()->plainText())->toBe('The the decision.')
        ->and($finding->refresh()->status)->toBe('open')
        ->and($finding->applied_version_id)->toBeNull()
        ->and($section->versions()->first()->label)->toBe('Undid: Repeated word');
});

it('undoes only the replacement when the chapter changed since', function () {
    [$user, $section, $finding] = ownedFinding('Earlier the the decision.', 'the the', 'the');

    $this->actingAs($user)->patchJson("/findings/{$finding->id}", ['status' => 'accepted'])->assertOk();

    $this->travel(5)->seconds();
    app(ManuscriptWriter::class)->save(
        section: $section->fresh(),
        content: ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Earlier the decision, and more.']],
        ]]],
        reason: 'manual',
    );

    $this->actingAs($user)->patchJson("/findings/{$finding->id}/reopen")->assertOk();

    expect($section->fresh()->plainText())->toBe('Earlier the the decision, and more.');
});

it('keeps a fix applied when it can no longer be undone safely', function () {
    [$user, $section, $finding] = ownedFinding('The the decision.', 'The the', 'The');

    $this->actingAs($user)->patchJson("/findings/{$finding->id}", ['status' => 'accepted'])->assertOk();

    $this->travel(5)->seconds();
    app(ManuscriptWriter::class)->save(
        section: $section->fresh(),
        content: ['type' => 'doc', 'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Rewritten from scratch.']],
        ]]],
        reason: 'manual',
    );

    $this->actingAs($user)->patchJson("/findings/{$finding->id}/reopen")->assertStatus(409);

    expect($finding->refresh()->status)->toBe('accepted')
        ->and($section->fresh()->plainText())->toBe('Rewritten from scratch.');
});
