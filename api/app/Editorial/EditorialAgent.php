<?php

namespace App\Editorial;

/**
 * The editorial agent catalogue.
 *
 * Names and descriptions come from the design reference and
 * PRODUCT_REQUIREMENTS.md. Having them in one enum rather than scattered
 * strings means the UI, the job dispatcher and the driver all agree on what
 * exists — and a typo becomes a type error instead of a review that silently
 * never runs.
 */
enum EditorialAgent: string
{
    case Developmental = 'developmental';
    case Line = 'line';
    case Copy = 'copy';
    case FactChecker = 'fact_checker';
    case Consistency = 'consistency';
    case SubjectMatter = 'subject_matter';
    case Accessibility = 'accessibility';

    public function label(): string
    {
        return match ($this) {
            self::Developmental => 'Developmental Editor',
            self::Line => 'Line Editor',
            self::Copy => 'Copy Editor',
            self::FactChecker => 'Fact Checker',
            self::Consistency => 'Consistency Agent',
            self::SubjectMatter => 'Subject Matter Review',
            self::Accessibility => 'Accessibility Check',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Developmental => 'Structure, pacing and argument at chapter level.',
            self::Line => 'Clarity, rhythm and sentence-level craft.',
            self::Copy => 'Grammar, punctuation and house style.',
            self::FactChecker => 'Claims that need a source before publication.',
            self::Consistency => 'Terminology, names and facts that disagree across the book.',
            self::SubjectMatter => 'Domain accuracy and regulatory language.',
            self::Accessibility => 'Readability, structure and inclusive language.',
        };
    }

    /**
     * The lowest plan tier that may run this agent.
     *
     * The general editors are on every plan: an author who cannot run a copy
     * edit has no product. The specialist reviewers are what the paid tiers
     * are for, which matches the plan table in config/billing.php.
     */
    public function minimumTier(): int
    {
        return match ($this) {
            self::SubjectMatter => 2,
            self::FactChecker, self::Consistency => 1,
            default => 0,
        };
    }

    /**
     * Whether this is a specialist reviewer, which costs more to run.
     *
     * Derived from the tier rather than listed separately, so the two cannot
     * drift apart.
     */
    public function isSpecialist(): bool
    {
        return $this->minimumTier() > 0;
    }

    /** The credit cost label for one run of this agent. */
    public function costLabel(): string
    {
        return $this->isSpecialist() ? 'specialist_run' : 'agent_run';
    }

    /** @return array<int, array<string, mixed>> */
    public static function catalogue(): array
    {
        return array_map(fn (self $a) => [
            'value' => $a->value,
            'label' => $a->label(),
            'description' => $a->description(),
            'specialist' => $a->isSpecialist(),
            'minimum_tier' => $a->minimumTier(),
            'cost' => config("billing.costs.{$a->costLabel()}"),
        ], self::cases());
    }
}
