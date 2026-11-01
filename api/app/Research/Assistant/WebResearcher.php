<?php

namespace App\Research\Assistant;

/**
 * Who answers a research question with sources from the web.
 *
 * Implementations MUST return only sources they actually retrieved, with an
 * excerpt quoted from the page, and MUST treat retrieved pages and the
 * author's material as untrusted data, never as instructions.
 */
interface WebResearcher
{
    public function name(): string;

    /** Sample output, not a model's research: never charged, always labelled. */
    public function simulated(): bool;

    public function research(ResearchBrief $brief): ResearchFindings;
}
