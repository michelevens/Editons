<?php

use App\Models\ManuscriptSection;

/**
 * The text sent to reviewers keeps its breaks (ADR — Safe apply, honest
 * prices, citation fixes). Lists ran together ("…everything else.Add daily
 * immersion…"), and every chapter got six to eight false, billed "missing
 * break between list items" findings.
 */
function srtSection(array $blocks): ManuscriptSection
{
    return (new ManuscriptSection)->forceFill(['content' => ['type' => 'doc', 'content' => $blocks]]);
}

function srtP(string $text): array
{
    return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]];
}

function srtItem(array ...$blocks): array
{
    return ['type' => 'listItem', 'content' => $blocks];
}

it('puts each list item on its own line, with a marker for reviewers', function () {
    $section = srtSection([
        srtP('Practical steps for today. Learn everything else.'),
        ['type' => 'bulletList', 'content' => [srtItem(srtP('Add daily immersion.')), srtItem(srtP('Find a tutor.'))]],
        ['type' => 'orderedList', 'attrs' => ['start' => 3], 'content' => [srtItem(srtP('Third.')), srtItem(srtP('Fourth.'))]],
    ]);

    expect($section->reviewText())->toBe("Practical steps for today. Learn everything else.\n\n- Add daily immersion.\n- Find a tutor.\n\n3. Third.\n4. Fourth.")
        ->and($section->plainText())->toBe("Practical steps for today. Learn everything else.\n\nAdd daily immersion.\nFind a tutor.\n\nThird.\nFourth.")
        ->and($section->plainText())->not->toContain('else.Add');
});

it('indents nested lists and keeps a list item’s second paragraph under it', function () {
    $section = srtSection([
        ['type' => 'bulletList', 'content' => [
            srtItem(srtP('Outer one.'), srtP('More about one.'), ['type' => 'bulletList', 'content' => [srtItem(srtP('Inner a.')), srtItem(srtP('Inner b.'))]]),
            srtItem(srtP('Outer two.')),
        ]],
    ]);

    expect($section->reviewText())->toBe("- Outer one.\n  More about one.\n  - Inner a.\n  - Inner b.\n- Outer two.");
});

it('marks quoted lines and keeps headings and hard breaks apart', function () {
    $section = srtSection([
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'The lesson']]],
        ['type' => 'blockquote', 'content' => [srtP('First quoted.'), srtP('Second quoted.'), ['type' => 'bulletList', 'content' => [srtItem(srtP('Quoted item.'))]]]],
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Line one'], ['type' => 'hardBreak'], ['type' => 'text', 'text' => 'Line two']]],
    ]);

    expect($section->reviewText())->toBe("The lesson\n\n> First quoted.\n> Second quoted.\n> - Quoted item.\n\nLine one\nLine two")
        ->and($section->plainText())->toBe("The lesson\n\nFirst quoted.\nSecond quoted.\nQuoted item.\n\nLine one\nLine two");
});

it('sends the reviewer the marked text, and finds a selection across list items', function () {
    $user = App\Models\User::factory()->subscribed('studio', 100)->create();
    $book = App\Models\Book::factory()->for($user)->create();
    $section = $book->sections()->create(['type' => 'chapter', 'title' => 'One', 'position' => 10, 'word_count' => 10, 'content' => ['type' => 'doc', 'content' => [
        srtP('Learn everything else.'),
        ['type' => 'bulletList', 'content' => [srtItem(srtP('Add daily immersion.')), srtItem(srtP('Find a tutor.'))]],
    ]]]);

    $driver = new class implements App\Editorial\Contracts\EditorialDriver, App\Editorial\Contracts\SimulatesReview
    {
        public ?string $sent = null;

        public function review(App\Editorial\EditorialAgent $agent, string $text): App\Editorial\ReviewResult
        {
            $this->sent = $text;

            return new App\Editorial\ReviewResult(findings: []);
        }

        public function name(): string
        {
            return 'fake';
        }
    };

    (new App\Editorial\ReviewRunner($driver, app(App\Billing\CreditLedger::class)))->run($section, App\Editorial\EditorialAgent::Copy, $user->id);
    expect($driver->sent)->toBe("Learn everything else.\n\n- Add daily immersion.\n- Find a tutor.");

    // A selection across two items is found despite the markers.
    $plan = app(App\Editorial\ReviewPlanner::class);
    expect((new ReflectionMethod($plan, 'locate'))->invoke($plan, $section->reviewText(), "Add daily immersion.\nFind a tutor."))
        ->toBe(['offset' => mb_strlen("Learn everything else.\n\n- "), 'length' => mb_strlen("Add daily immersion.\n- Find a tutor.")]);
});
