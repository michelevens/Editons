<?php

use App\Editorial\EditorialAgent;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;

/**
 * Master's and doctoral examiners, and the degree book types.
 *
 * These are for students writing work they will submit. The examiners read
 * as an examiner would and explain what is missing; they never write the
 * student's text, which is the property these tests hold most firmly.
 */
function thesisChapter(User $user, string $type, string $text): ManuscriptSection
{
    $book = $user->books()->create(['title' => 'A Thesis', 'book_type' => $type]);

    $section = $book->sections()->create([
        'type' => 'chapter',
        'title' => 'Literature review',
        'position' => 10,
        'content' => ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
        ]],
    ]);
    $section->forceFill(['word_count' => ManuscriptSection::countWords($section->content)])->save();

    return $section;
}

it('scaffolds a master\'s thesis with its standard chapters', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/books', [
        'title' => 'Remote Work and Trust',
        'book_type' => 'masters_thesis',
        'chapters' => 6,
    ])->assertRedirect();

    $book = Book::where('title', 'Remote Work and Trust')->firstOrFail();
    $titles = $book->sections()->orderBy('position')->pluck('title', 'type');

    expect($book->sections()->where('type', 'front_matter')->pluck('title')->all())
        ->toBe(['Abstract', 'Declaration', 'Acknowledgements'])
        ->and($book->sections()->where('type', 'back_matter')->pluck('title')->all())
        ->toBe(['References', 'Appendices'])
        ->and($book->sections()->where('type', 'chapter')->count())->toBe(6);
});

it('puts the right examiner first for each degree', function () {
    $masters = EditorialAgent::catalogue(config('booktypes.masters_thesis.agents'));
    $doctoral = EditorialAgent::catalogue(config('booktypes.phd_dissertation.agents'));

    expect($masters[0]['value'])->toBe('masters_examiner')
        ->and($doctoral[0]['value'])->toBe('doctoral_examiner')
        // Ordering, never filtering: every agent is still there.
        ->and(count($masters))->toBe(count(EditorialAgent::cases()));
});

it('flags an uncited empirical claim and an overclaim', function () {
    $user = User::factory()->subscribed()->create();
    $section = thesisChapter($user, 'masters_thesis',
        'This thesis examines trust in remote teams. Studies show that remote workers are 40% less engaged. '
        .'Trust is always the decisive factor in every team.');

    $findings = $this->actingAs($user)
        ->postJson("/sections/{$section->id}/review", ['agent' => 'masters_examiner'])
        ->assertOk()
        ->json('findings');

    $titles = collect($findings)->pluck('title');

    expect($titles)->toContain('Claim without a citation')
        ->and($titles)->toContain('Possible overclaim');
});

it('does not flag a claim that carries a citation', function () {
    $user = User::factory()->subscribed()->create();
    $section = thesisChapter($user, 'masters_thesis',
        'This thesis examines trust in remote teams. Studies show that remote workers report lower engagement (Smith, 2021).');

    $titles = collect($this->actingAs($user)
        ->postJson("/sections/{$section->id}/review", ['agent' => 'masters_examiner'])
        ->json('findings'))->pluck('title');

    expect($titles)->not->toContain('Claim without a citation');
});

it('asks a doctoral chapter for its contribution', function () {
    $user = User::factory()->subscribed()->create();
    $section = thesisChapter($user, 'phd_dissertation',
        'This dissertation investigates how institutions shape trust. The chapter reviews the field.');

    $titles = collect($this->actingAs($user)
        ->postJson("/sections/{$section->id}/review", ['agent' => 'doctoral_examiner'])
        ->json('findings'))->pluck('title');

    expect($titles)->toContain('Contribution not stated here');
});

it('never writes the student\'s text', function () {
    // The property that matters most: whatever the examiner flags, it
    // offers no replacement wording to accept.
    $user = User::factory()->subscribed()->create();
    $section = thesisChapter($user, 'phd_dissertation',
        'Research shows that 70% of firms fail. This always proves the theory. Data indicates a clear trend.');

    foreach (['masters_examiner', 'doctoral_examiner'] as $agent) {
        $findings = $this->actingAs($user)
            ->postJson("/sections/{$section->id}/review", ['agent' => $agent])
            ->assertOk()
            ->json('findings');

        expect($findings)->not->toBeEmpty();

        foreach ($findings as $finding) {
            expect($finding['suggestion'] ?? null)->toBeNull();
        }
    }

    expect(EditorialAgent::MastersExaminer->coachesOnly())->toBeTrue()
        ->and(EditorialAgent::DoctoralExaminer->coachesOnly())->toBeTrue()
        ->and(EditorialAgent::Line->coachesOnly())->toBeFalse();
});

it('keeps the examiners on paid plans', function () {
    $user = User::factory()->create();
    $section = thesisChapter($user, 'masters_thesis', 'This thesis examines trust. Studies show it matters.');

    $this->actingAs($user)
        ->postJson("/sections/{$section->id}/review", ['agent' => 'masters_examiner'])
        ->assertStatus(422)
        ->assertJsonPath('errors.agent.0', "Master's Examiner is part of the Author plan. You are on Free.");
});

it('does not treat the thesis\'s own results as uncited claims', function () {
    // A results chapter reports numbers; they are findings, not borrowed
    // claims. And a decimal point is not the end of a sentence.
    $user = User::factory()->subscribed()->create();
    $section = thesisChapter($user, 'masters_thesis',
        'This thesis examines churn prediction. The hybrid logistic model reached 79.41% accuracy against 80.20% for the traditional one. '
        .'The difference is not significant in this sample.');

    $findings = collect($this->actingAs($user)
        ->postJson("/sections/{$section->id}/review", ['agent' => 'masters_examiner'])
        ->json('findings'));

    expect($findings->pluck('title'))->not->toContain('Claim without a citation')
        ->and($findings->pluck('excerpt')->filter(fn ($e) => str_starts_with($e, '41%')))->toBeEmpty();
});
