<?php

namespace App\Ai;

use App\Ai\Contracts\Writer;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Builds the configured writer, the way App\Editorial\DriverFactory builds
 * the reviewer: each config block names its class and options, a driver
 * missing its credentials fails here rather than at the first chapter, and
 * nothing in this file names a vendor.
 */
class WriterFactory
{
    public function __construct(private readonly Container $container) {}

    public function make(?string $name = null): Writer
    {
        $name ??= config('writer.driver');

        $config = config("writer.drivers.{$name}")
            ?? throw new InvalidArgumentException("Unknown writer driver [{$name}].");

        foreach ($config['requires'] ?? [] as $key) {
            if (blank(data_get($config, $key))) {
                throw new InvalidArgumentException("WRITER_DRIVER={$name} but [{$key}] is not configured. Set it, or use WRITER_DRIVER=fake.");
            }
        }

        return $this->container->make($config['class'], array_diff_key($config, array_flip(['class', 'requires'])));
    }

    /** Whether the configured writer produces sample text. Read from config: asking builds no client. */
    public static function simulated(?string $name = null): bool
    {
        return (config('writer.drivers.'.($name ?? config('writer.driver')).'.class')) === Writers\FakeWriter::class;
    }
}
