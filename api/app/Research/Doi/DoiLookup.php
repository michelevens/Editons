<?php

namespace App\Research\Doi;

use Illuminate\Container\Attributes\Bind;

/**
 * Turns a DOI into the facts a registry holds about it.
 *
 * An interface so tests never reach the network (they bind FakeDoiLookup)
 * and so another registry (DataCite) can be added beside Crossref. What
 * comes back is a suggestion for the author to review and save — it is
 * cleaned like an imported file and arrives unverified, because a registry
 * record proves the work exists, not that it says what the manuscript
 * claims it says.
 */
#[Bind(CrossrefDoiLookup::class)]
interface DoiLookup
{
    /**
     * @return array<string, string|null>|null source fields, or null when
     *                                         the registry has no such DOI
     *
     * @throws DoiLookupFailed when the registry cannot be reached
     */
    public function find(string $doi): ?array;
}
