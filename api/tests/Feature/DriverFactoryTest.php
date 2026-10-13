<?php

use App\Editorial\Contracts\EditorialDriver;
use App\Editorial\DriverFactory;
use App\Editorial\Drivers\AnthropicDriver;
use App\Editorial\Drivers\FakeDriver;

/**
 * The carrier is a deployment decision.
 *
 * These tests exist because the owner runs Anthropic, Vertex and OpenAI in
 * different places and will swap between them. What must hold is that
 * swapping touches configuration only — never shared code, and never
 * anything above the driver interface.
 */
function factory(): DriverFactory
{
    return app(DriverFactory::class);
}

it('defaults to the fake carrier so a fresh clone runs without credentials', function () {
    expect(app(EditorialDriver::class))->toBeInstanceOf(FakeDriver::class);
});

it('builds whichever carrier the config names', function () {
    config(['editorial.drivers.anthropic.apiKey' => 'test-key']);

    expect(factory()->make('anthropic'))->toBeInstanceOf(AnthropicDriver::class);
});

it('passes each carrier its own options by name', function () {
    // The factory never learns any provider's constructor shape; it hands
    // over the config block. This is what keeps adding a carrier to one file.
    config([
        'editorial.drivers.anthropic.apiKey' => 'test-key',
        'editorial.drivers.anthropic.model' => 'claude-opus-5',
    ]);

    $driver = factory()->make('anthropic');

    expect($driver)->toBeInstanceOf(AnthropicDriver::class)
        ->and($driver->name())->toBe('anthropic');
});

it('refuses a carrier whose credentials are missing', function () {
    // Falling back to heuristics here would have the product claiming AI
    // review while running regex, which is the failure the seam prevents.
    config(['editorial.drivers.anthropic.apiKey' => null]);

    expect(fn () => factory()->make('anthropic'))
        ->toThrow(InvalidArgumentException::class, 'is not configured');
});

it('names the configured carriers when asked for an unknown one', function () {
    expect(fn () => factory()->make('telepathy'))
        ->toThrow(InvalidArgumentException::class, 'Unknown editorial driver');
});

it('rejects a carrier entry with no class', function () {
    config(['editorial.drivers.broken' => ['requires' => []]]);

    expect(fn () => factory()->make('broken'))
        ->toThrow(InvalidArgumentException::class, "has no 'class' configured");
});

it('swaps carrier through configuration alone', function () {
    // The whole requirement in one assertion: nothing but config changed,
    // and the application resolves a different provider.
    expect(app(EditorialDriver::class))->toBeInstanceOf(FakeDriver::class);

    config([
        'editorial.driver' => 'anthropic',
        'editorial.drivers.anthropic.apiKey' => 'test-key',
    ]);

    // Forget the resolved instance so the binding runs again, as it would on
    // a fresh boot with a different EDITORIAL_DRIVER.
    app()->forgetInstance(EditorialDriver::class);

    expect(app(EditorialDriver::class))->toBeInstanceOf(AnthropicDriver::class);
});

it('lets a new carrier be added with a class and a config block', function () {
    // Proves the extension path without shipping a half-built provider:
    // register an anonymous driver and resolve it.
    config([
        'editorial.drivers.stub' => [
            'class' => StubCarrier::class,
            'requires' => ['token'],
            'token' => 'present',
        ],
    ]);

    expect(factory()->make('stub'))->toBeInstanceOf(StubCarrier::class)
        ->and(factory()->make('stub')->name())->toBe('stub');
});

class StubCarrier implements EditorialDriver
{
    public function __construct(public readonly string $token) {}

    public function review(App\Editorial\EditorialAgent $agent, string $text): App\Editorial\ReviewResult
    {
        return new App\Editorial\ReviewResult(findings: []);
    }

    public function name(): string
    {
        return 'stub';
    }
}
