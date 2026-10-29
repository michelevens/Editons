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
    ) {}

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
