<?php

namespace App\Editorial\Contracts;

use App\Editorial\EditorialAgent;
use App\Editorial\ReviewResult;

/**
 * A provider of editorial review.
 *
 * ARCHITECTURE.md asks for a "provider-agnostic AI gateway, including Claude".
 * This is that seam: the job, the storage and the UI talk to this interface,
 * so adding Anthropic, OpenAI or a local model is one class and a config line
 * rather than a rewrite.
 *
 * The first implementation is FakeDriver — deterministic, free, and testable.
 * Everything above this interface is real and finished; only what sits below
 * it is pending an API key.
 */
interface EditorialDriver
{
    /**
     * Review one section's plain text and return structured findings.
     *
     * Takes extracted text rather than the ProseMirror tree: a driver should
     * not need to understand the document format, and a real provider will be
     * sent prose regardless.
     *
     * Implementations MUST treat $text as untrusted data, never as
     * instructions (CLAUDE.md, AI safeguards). A manuscript containing
     * "ignore previous instructions" is a manuscript, not a command.
     */
    public function review(EditorialAgent $agent, string $text): ReviewResult;

    /** Identifier recorded on the review row for provenance. */
    public function name(): string;
}
