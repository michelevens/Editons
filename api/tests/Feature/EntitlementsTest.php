<?php

use App\Billing\CreditLedger;
use App\Billing\Entitlements;
use App\Editorial\EditorialAgent;
use App\Models\BookExport;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * What a plan allows.
 *
 * The prototype gates features in the client, which tells an honest user what
 * to expect and stops nobody. These cover the server-side versions.
 */

// --- The pricing table must describe the real product -------------------

it('never promises an export format the application cannot build', function () {
    // This is the bug these tests were written after: the plan table carried
    // 'kpf' and 'large_print', which nothing produces, and omitted 'md',
    // which the exporter has always produced. Markdown was therefore
    // unavailable on every plan including the most expensive one.
    $buildable = array_keys(BookExport::FORMATS);

    // The table uses the extension an author recognises; the exporter names
    // the file it builds.
    $buildable = array_map(
        fn (string $format) => $format === 'pdf_print' ? 'pdf' : $format,
        $buildable,
    );

    foreach (config('billing.plans') as $key => $plan) {
        foreach ($plan['formats'] as $format) {
            // assertContains rather than expect()->toContain(): Pest treats a
            // second argument as another expected value, not a message, and
            // the message is the whole point of this test.
            $this->assertContains(
                $format,
                $buildable,
                "The {$key} plan offers [{$format}], which nothing generates.",
            );
        }
    }
});

it('offers every buildable format on the top plan', function () {
    // Otherwise a format exists that nobody can ever pay for.
    $top = collect(config('billing.plans'))->sortByDesc('tier')->first();

    foreach (array_keys(BookExport::FORMATS) as $format) {
        // The print files are PDFs, entitled as 'pdf' (PublishingController::planFormat).
        $name = in_array($format, ['pdf_print', 'pdf_interior', 'cover_wrap'], true) ? 'pdf' : $format;

        $this->assertContains($name, $top['formats'], "No plan includes [{$format}].");
    }
});

it('prices every agent in the catalogue', function () {
    // An unpriced agent throws when it is run, which would be found by a
    // paying customer rather than here.
    foreach (EditorialAgent::cases() as $agent) {
        expect(CreditLedger::costOf($agent->costLabel()))->toBeGreaterThan(0);
    }
});

it('keeps a general editor available on the free plan', function () {
    // An author who cannot run a copy edit has no product to try.
    $free = User::factory()->create(['plan' => 'free']);

    expect(app(Entitlements::class)->canRunAgent($free, EditorialAgent::Copy))->toBeTrue()
        ->and(app(Entitlements::class)->canRunAgent($free, EditorialAgent::Line))->toBeTrue();
});

// --- Agent gating -------------------------------------------------------

it('refuses a specialist agent on the free plan, naming the plan that has it', function () {
    // "Not available on your plan" leaves somebody guessing what to buy.
    $user = User::factory()->create(['plan' => 'free']);

    try {
        app(Entitlements::class)->assertCanRunAgent($user, EditorialAgent::SubjectMatter);
        $this->fail('A free account was allowed to run a specialist reviewer.');
    } catch (ValidationException $e) {
        $message = $e->errors()['agent'][0];

        expect($message)->toContain('Studio')
            ->and($message)->toContain('Free');
    }
});

it('allows a tier-1 agent on the author plan but not a tier-2 one', function () {
    $author = User::factory()->create(['plan' => 'author']);
    $entitlements = app(Entitlements::class);

    expect($entitlements->canRunAgent($author, EditorialAgent::FactChecker))->toBeTrue()
        ->and($entitlements->canRunAgent($author, EditorialAgent::SubjectMatter))->toBeFalse();
});

it('allows everything on the top plan', function () {
    $studio = User::factory()->create(['plan' => 'studio']);

    foreach (EditorialAgent::cases() as $agent) {
        expect(app(Entitlements::class)->canRunAgent($studio, $agent))->toBeTrue();
    }
});

// --- Credit gating ------------------------------------------------------

it('refuses an action the balance will not cover, and says by how much', function () {
    // broke(): an account on a plan whose allowance has already been granted
    // this month, so the balance stays at zero rather than being topped up
    // by the first read.
    $user = User::factory()->broke()->create();

    try {
        app(Entitlements::class)->assertCanAfford($user, 'agent_run');
        $this->fail('An empty account was allowed to spend.');
    } catch (ValidationException $e) {
        expect($e->errors()['credits'][0])->toContain('you have 0');
    }
});

it('allows it once there are credits', function () {
    $user = User::factory()->subscribed()->create();

    app(Entitlements::class)->assertCanAfford($user, 'agent_run');

    expect(true)->toBeTrue();
});

// --- Export gating ------------------------------------------------------

it('refuses a format the plan excludes, naming the plan that includes it', function () {
    $free = User::factory()->create(['plan' => 'free']);

    try {
        app(Entitlements::class)->assertCanExport($free, 'html');
        $this->fail('A free account was allowed an HTML export.');
    } catch (ValidationException $e) {
        expect($e->errors()['format'][0])->toContain('Studio');
    }
});

it('allows a format the plan includes', function () {
    $author = User::factory()->create(['plan' => 'author']);

    app(Entitlements::class)->assertCanExport($author, 'epub');

    expect(true)->toBeTrue();
});

// --- The credit strip ---------------------------------------------------

it('summarises the balance against the monthly allowance', function () {
    $user = User::factory()->subscribed('author', 250)->create();

    $summary = app(Entitlements::class)->summaryFor($user);

    expect($summary['plan_name'])->toBe('Author')
        ->and($summary['credits'])->toBe(250)
        ->and($summary['monthly_credits'])->toBe(1000)
        ->and($summary['credits_pct'])->toBe(25)
        ->and($summary['low'])->toBeFalse();
});

it('flags a balance that is nearly gone', function () {
    $user = User::factory()->subscribed('author', 50)->create();

    expect(app(Entitlements::class)->summaryFor($user)['low'])->toBeTrue();
});

it('does not divide by zero on a plan with no allowance', function () {
    // The free plan's allowance could be set to 0, and a percentage of
    // nothing must not take the studio down.
    config(['billing.plans.free.credits' => 0]);

    $summary = app(Entitlements::class)->summaryFor(User::factory()->create(['plan' => 'free']));

    expect($summary['credits_pct'])->toBe(0)
        ->and($summary['low'])->toBeFalse();
});
