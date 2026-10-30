<?php

namespace Tests\Support;

use App\Research\Doi\DoiLookup;
use App\Research\Doi\DoiLookupFailed;

/**
 * DoiLookup without the network: answers from a fixed table. A DOI mapped
 * to `false` simulates the registry being unreachable.
 */
class FakeDoiLookup implements DoiLookup
{
    /** @var list<string> every DOI asked for, in order */
    public array $asked = [];

    /** @param  array<string, array<string, string|null>|false>  $records */
    public function __construct(private array $records = []) {}

    public function find(string $doi): ?array
    {
        $this->asked[] = $doi;
        $record = $this->records[strtolower($doi)] ?? null;

        if ($record === false) {
            throw new DoiLookupFailed('unreachable');
        }

        return $record;
    }
}
