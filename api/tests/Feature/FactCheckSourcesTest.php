<?php

use App\Billing\MonthlyAllowance;
use App\Editorial\Contracts\EditorialDriver;
use App\Editorial\Drivers\AnthropicDriver;
use App\Editorial\EditorialAgent;
use App\Editorial\ReviewRunner;
use App\Models\Book;
use App\Models\EditorialFinding;
use App\Models\ManuscriptSection;
use App\Models\ResearchRun;
use App\Models\ResearchSource;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * The fact-checker uses the book's saved sources, and the real agents are
 * told about the book (ADR — AI research, source-backed fact-checking,
 * cover templates). The fake driver and a faked HTTP layer; never the
 * real API.
 */
function fcBook(User $user, array $attributes = []): Book
{
    $user->forceFill(['plan' => 'author'])->save();

    return Book::factory()->for($user)->create($attributes + ['book_type' => 'nonfiction', 'language' => 'English', 'genre' => 'Nonfiction']);
}

function fcSection(Book $book, string $text): ManuscriptSection
{
    return $book->sections()->create([
        'type' => 'chapter',
        'title' => 'Facts',
        'position' => 1,
        'content' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]],
    ]);
}

function fcSource(Book $book, string $notes, array $attributes = []): ResearchSource
{
    $source = $book->sources()->create($attributes + ['kind' => 'website', 'title' => 'Migration Policy Institute', 'url' => 'https://www.migrationpolicy.org/haiti', 'notes' => $notes]);

    return $source;
}

function useRealDriver(): void
{
    app()->instance(EditorialDriver::class, new AnthropicDriver(apiKey: 'sk-ant-test-key-123456', model: 'claude-sonnet-5-5'));
}

function fcResponse(array $findings): array
{
    return [
        'model' => 'claude-sonnet-5-5',
        'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => json_encode(['findings' => $findings])]],
        'usage' => ['input_tokens' => 1000, 'output_tokens' => 200],
    ];
}

beforeEach(fn () => config(['research.driver' => 'fake']));

it('labels sample fact-check findings supported by a saved source, or unverified', function () {
    $user = User::factory()->create();
    $book = fcBook($user);
    $source = fcSource($book, 'About 730 thousand Haitian immigrants lived in the United States in 2021. Most arrived after 1980.');
    $section = fcSection($book, 'In 2021, 730 thousand Haitian immigrants lived in the United States. Rents in Miami rose 40 percent.');

    $findings = $this->actingAs($user)
        ->postJson("/sections/{$section->id}/review", ['agent' => 'fact_checker'])
        ->assertOk()
        ->json('findings');

    $supported = collect($findings)->firstWhere(fn ($f) => str_contains($f['excerpt'], '730'));
    $unverified = collect($findings)->firstWhere(fn ($f) => str_contains($f['excerpt'], 'Rents'));

    expect($supported['evidence']['verdict'])->toBe('supported')
        ->and($supported['evidence']['source_id'])->toBe($source->id)
        ->and($supported['evidence']['source_excerpt'])->toBe('About 730 thousand Haitian immigrants lived in the United States in 2021.')
        ->and($supported['evidence']['source_url'])->toBe('https://www.migrationpolicy.org/haiti')
        ->and($unverified['evidence']['verdict'])->toBe('unverified')
        ->and($unverified['evidence']['source_id'])->toBeNull();

    // One click: the finding's source becomes a citation through the
    // existing citation flow, with its marker for the editor.
    $this->actingAs($user)
        ->postJson("/sections/{$section->id}/citations", [
            'claim' => $supported['excerpt'],
            'research_source_id' => $supported['evidence']['source_id'],
            'excerpt' => $supported['excerpt'],
            'status' => 'supported',
        ])
        ->assertCreated()
        ->assertJsonPath('marker.kind', 'text');
});

it('never labels a claim supported without a saved source that says so', function () {
    useRealDriver();
    $user = User::factory()->create();
    $book = fcBook($user);
    app(MonthlyAllowance::class)->ensureGranted($user);
    $source = fcSource($book, 'About 730,000 Haitian immigrants lived in the United States in 2021.');
    $disputed = fcSource($book, 'Haiti has 50 million people.', ['title' => 'Bad blog', 'url' => 'https://bad.example.net']);
    $disputed->forceFill(['verification' => 'disputed'])->save();
    $section = fcSection($book, 'About 730,000 Haitian immigrants lived in the US. Haiti has 50 million people. The moon is cheese.');

    Http::fake(['api.anthropic.com/*' => Http::response(fcResponse([
        ['severity' => 'warning', 'title' => 'Figure', 'excerpt' => 'About 730,000 Haitian immigrants lived in the US.', 'verdict' => 'supported', 'source_id' => $source->id, 'source_excerpt' => 'About 730,000 Haitian immigrants lived in the United States in 2021.'],
        // A disputed source is never sent, so it cannot back a claim.
        ['severity' => 'critical', 'title' => 'Population', 'excerpt' => 'Haiti has 50 million people.', 'verdict' => 'supported', 'source_id' => $disputed->id, 'source_excerpt' => 'Haiti has 50 million people.'],
        // A quote the source does not contain.
        ['severity' => 'warning', 'title' => 'Cheese', 'excerpt' => 'The moon is cheese.', 'verdict' => 'contradicted', 'source_id' => $source->id, 'source_excerpt' => 'The moon is rock.'],
    ]))]);

    $this->actingAs($user)->postJson("/sections/{$section->id}/review", ['agent' => 'fact_checker'])->assertOk();

    $byTitle = EditorialFinding::all()->keyBy('title');

    expect($byTitle['Figure']->evidence['verdict'])->toBe('supported')
        ->and($byTitle['Figure']->evidence['source_id'])->toBe($source->id)
        ->and($byTitle['Population']->evidence['verdict'])->toBe('unverified')
        ->and($byTitle['Cheese']->evidence['verdict'])->toBe('unverified');

    Http::assertSent(function (HttpRequest $request) use ($source, $disputed) {
        $docs = collect($request['messages'][0]['content'])->keyBy('title');
        $library = json_decode($docs['Saved research sources']['source']['data'], true);
        $schema = $request['output_config']['format']['schema']['properties']['findings']['items'];

        return collect($library)->pluck('source_id')->all() === [$source->id]
            && ! collect($library)->pluck('source_id')->contains($disputed->id)
            && in_array('verdict', $schema['required'], true)
            && $schema['properties']['verdict']['enum'] === ['supported', 'contradicted', 'unverified']
            && str_contains($request['system'], 'never say a claim is verified');
    });
});

it('tells the real agents the book type, genre, language and reader', function () {
    $user = User::factory()->create();
    $novel = fcBook($user, ['book_type' => 'fiction', 'genre' => 'Fiction', 'language' => 'French', 'audience' => 'Adult readers of literary fiction']);
    $thesis = fcBook($user, ['book_type' => 'masters_thesis', 'genre' => 'Education', 'language' => 'English']);
    $runner = app(ReviewRunner::class);
    $driver = new AnthropicDriver(apiKey: 'k', model: 'claude-sonnet-5-5');

    $novelContext = $runner->contextFor(fcSection($novel, '"We left in 1990," she said.'));
    $fact = $driver->payload(EditorialAgent::FactChecker, 'x', $novelContext);
    $line = $driver->payload(EditorialAgent::Line, 'x', $novelContext);
    $profile = collect($fact['messages'][0]['content'])->firstWhere('title', 'Book profile');

    expect($profile['source']['data'])->toContain('Kind of book: Fiction')
        ->toContain('Genre: Fiction')
        ->toContain('Language: French')
        ->toContain('Reader: Adult readers of literary fiction')
        ->and($fact['system'])->toContain('This book is fiction. Do not fact-check dialogue')
        ->and($line['system'])->toContain('judge it as speech')
        ->and($line['system'])->not->toContain('academic writing');

    $thesisContext = $runner->contextFor(fcSection($thesis, 'Text.'));
    $copy = $driver->payload(EditorialAgent::Copy, 'x', $thesisContext);

    expect($copy['system'])->toContain("academic writing at master's level")
        ->and($copy['system'])->not->toContain('This book is fiction');
});

it('keeps the request unchanged for a review given no book', function () {
    $driver = new AnthropicDriver(apiKey: 'k', model: 'claude-sonnet-5-5');
    $payload = $driver->payload(EditorialAgent::Line, 'Prose.');

    expect(collect($payload['messages'][0]['content'])->pluck('title')->all())->toBe(['Chapter under review'])
        ->and($payload['system'])->not->toContain('Book profile');
});

it('searches the web for unverified claims when the author allows it', function () {
    $user = User::factory()->create();
    $book = fcBook($user);
    $section = fcSection($book, 'Rents in Miami rose 40 percent.');

    $findings = $this->actingAs($user)
        ->postJson("/sections/{$section->id}/review", ['agent' => 'fact_checker', 'web' => true])
        ->assertOk()
        ->json('findings');

    $evidence = $findings[0]['evidence'];

    expect($evidence['verdict'])->toBe('unverified')
        ->and($evidence['web'])->toBe('completed')
        ->and($evidence['candidates']['results'])->toHaveCount(3)
        ->and($evidence['candidates']['results'][0]['url'])->toStartWith('https://example.org/')
        ->and(ResearchRun::where('mode', 'claim')->count())->toBe(1);

    // Without the option, nobody searches.
    $this->actingAs($user)->postJson("/sections/{$section->id}/review", ['agent' => 'fact_checker'])->assertOk();
    expect(ResearchRun::count())->toBe(1);
});
