<?php

namespace App\Editorial;

/**
 * One issue, as a driver reports it.
 *
 * Distinct from the EditorialFinding model: this is the transport shape
 * crossing the driver boundary, with no database identity and no resolution
 * state. Keeping them separate means a driver cannot accidentally set a
 * finding's status or claim it was already accepted.
 */
final class Finding
{
    /**
     * @param  'critical'|'warning'|'suggestion'  $severity
     * @param  string|null  $excerpt   the text this is about, copied verbatim
     * @param  string|null  $suggestion proposed replacement, or null to flag only
     */
    public function __construct(
        public readonly string $severity,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly ?string $excerpt = null,
        public readonly ?string $suggestion = null,
        public readonly ?int $fromOffset = null,
        public readonly ?int $toOffset = null,
    ) {}
}
