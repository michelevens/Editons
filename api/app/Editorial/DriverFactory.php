<?php

namespace App\Editorial;

use App\Editorial\Contracts\EditorialDriver;
use App\Editorial\Contracts\SimulatesReview;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Builds the configured editorial driver.
 *
 * Carriers change. This project already uses Anthropic, Google Vertex and
 * OpenAI elsewhere, so the provider is a deployment decision, not an
 * architectural one — `EDITORIAL_DRIVER` picks it and nothing above the
 * interface moves.
 *
 * Each driver's config block carries its own `class` and its own options, so
 * adding a carrier is a class plus a config entry. There is deliberately no
 * per-provider branching here: the moment this file needs an `if` naming a
 * vendor, the seam has stopped doing its job.
 */
class DriverFactory
{
    public function __construct(private readonly Container $container) {}

    public function make(?string $name = null): EditorialDriver
    {
        $name ??= config('editorial.driver');

        $config = config("editorial.drivers.{$name}")
            ?? throw new InvalidArgumentException(
                "Unknown editorial driver [{$name}]. Configured: "
                .implode(', ', array_keys(config('editorial.drivers', []))).'.',
            );

        $class = $config['class']
            ?? throw new InvalidArgumentException(
                "Editorial driver [{$name}] has no 'class' configured.",
            );

        // A driver needing credentials says so, and we fail here rather than
        // at the first review. A provider that silently fell back to local
        // heuristics would have the product claiming AI review while running
        // regex — the one failure this whole seam exists to prevent.
        foreach ($config['requires'] ?? [] as $key) {
            if (blank(data_get($config, $key))) {
                throw new InvalidArgumentException(
                    "EDITORIAL_DRIVER={$name} but [{$key}] is not configured. "
                    .'Set it, or use EDITORIAL_DRIVER=fake.',
                );
            }
        }

        // Options are passed by name, so each driver declares the constructor
        // it wants and this factory never learns any provider's shape.
        $options = array_diff_key($config, array_flip(['class', 'requires']));

        return $this->container->make($class, $options);
    }

    /**
     * Does the configured driver (or the named one) produce sample output
     * rather than a model's review? Read from config, so asking never
     * builds a provider client.
     */
    public static function simulated(?string $name = null): bool
    {
        $class = config('editorial.drivers.'.($name ?? config('editorial.driver')).'.class');

        return is_string($class) && is_subclass_of($class, SimulatesReview::class);
    }
}
