<?php

namespace App\Editorial;

use App\Editorial\Style\StyleRules;

/**
 * What a review is told beyond the text (ADR — Style guides and review
 * options): the style rules, the author's focus notes, the sensitivity.
 *
 * None of it comes from the manuscript. The rules and focus are author
 * configuration, sent to the model in their own delimited blocks, apart
 * from the chapter, which stays untrusted data; nothing written in a
 * chapter can reach these fields.
 */
final class ReviewContext
{
    public const SENSITIVITIES = ['light', 'standard', 'thorough'];

    /** The agents that read the style guide. */
    public const STYLE_AGENTS = [EditorialAgent::Copy, EditorialAgent::Line, EditorialAgent::Consistency];

    public const MAX_FOCUS = 500;

    /**
     * @param  array<string, mixed>|null  $style  normalised StyleRules, or null for none
     * @param  list<string>  $disabledRules
     */
    public function __construct(
        public readonly ?array $style = null,
        public readonly ?string $focus = null,
        public readonly string $sensitivity = 'standard',
        public readonly array $disabledRules = [],
        public readonly ?int $styleVersionId = null,
        // The book (ADR — AI research, source-backed fact-checking, cover
        // templates): {profile: {label: value}, fiction: bool, academic:
        // ?string, language: string}. Author configuration, from the
        // book's settings; empty when the review was not given a book.
        public readonly array $book = [],
        // May the fact-checker search the web for claims the saved sources
        // leave unverified? The author's choice per review; off by default.
        public readonly bool $web = false,
        // The book's saved sources the fact-checker checks claims against:
        // list of {id, reference, title, url, text, verification}.
        public readonly array $sources = [],
        // What the chapter already cites: its citations (claim, excerpt,
        // marker, the source and its stored excerpt) and its footnotes
        // (the text they follow and the note). The fact-checker reads them
        // so a sourced claim is checked against its source, not called
        // unsourced. Neither is in the plain text the findings anchor to.
        public readonly array $attached = [],
    ) {}

    /** The same options, with the saved sources the fact-checker reads. */
    public function withSources(array $sources, ?array $attached = null): self
    {
        return new self($this->style, $this->focus, $this->sensitivity, $this->disabledRules, $this->styleVersionId, $this->book, $this->web, $sources, $attached ?? $this->attached);
    }

    /** The same options, with web checking switched on or off. */
    public function withWeb(bool $web): self
    {
        return new self($this->style, $this->focus, $this->sensitivity, $this->disabledRules, $this->styleVersionId, $this->book, $web, $this->sources, $this->attached);
    }

    /** Is the book fiction (from its settings)? Dialogue and invented events are not fact-checked. */
    public function fiction(): bool
    {
        return (bool) ($this->book['fiction'] ?? false);
    }

    /** The same options with nothing in them: today's review. */
    public static function none(): self
    {
        return new self;
    }

    /** The style rules this agent is given, or null when it reads none. */
    public function styleFor(EditorialAgent $agent): ?array
    {
        if ($this->style === null || ! in_array($agent, self::STYLE_AGENTS, true)) {
            return null;
        }

        $prompt = StyleRules::forPrompt($this->style);

        return $prompt === [] ? null : $prompt;
    }

    /** Does this context change the request at all for this agent? */
    public function isDefaultFor(EditorialAgent $agent): bool
    {
        return $this->styleFor($agent) === null && $this->focus === null && $this->sensitivity === 'standard';
    }

    /** The dictionary that suppresses findings, whichever agent ran. */
    public function dictionary(): array
    {
        return $this->style['dictionary'] ?? [];
    }

    /** The lowest severity kept: light keeps warnings and above. */
    public function minimumSeverity(): string
    {
        return config("editorial.sensitivity.{$this->sensitivity}.min_severity", 'suggestion');
    }
}
