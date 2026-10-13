<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use App\Editorial\Contracts\EditorialDriver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bind the editorial driver from config. This one line is the whole
        // provider-agnostic gateway ARCHITECTURE.md asks for: swapping to
        // Anthropic means a new class and a config value, not a refactor.
        $this->app->bind(EditorialDriver::class, function ($app) {
            $driver = config('editorial.driver');
            $class = config("editorial.drivers.{$driver}")
                ?? throw new \InvalidArgumentException("Unknown editorial driver [{$driver}].");

            if ($driver === 'anthropic') {
                $key = config('editorial.anthropic.key');

                // Fail here rather than at the first review. A driver that
                // silently falls back to heuristics would have the product
                // claiming AI review while running regex, which is the one
                // failure the whole seam exists to prevent.
                throw_unless($key, \InvalidArgumentException::class,
                    'EDITORIAL_DRIVER=anthropic but ANTHROPIC_API_KEY is not set.');

                return new $class(
                    apiKey: $key,
                    model: config('editorial.anthropic.model'),
                    maxTokens: config('editorial.anthropic.max_tokens'),
                    timeout: config('editorial.anthropic.timeout'),
                    maxCharacters: config('editorial.anthropic.max_characters'),
                );
            }

            return $app->make($class);
        });

        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
