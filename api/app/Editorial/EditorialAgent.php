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

    /**
     * Every agent, optionally ordered for a kind of book.
     *
     * $preferred comes from config/booktypes.php, where each type names the
     * reviewers that matter most for it — a cookbook wants consistency, a
     * biography wants the fact checker. That list had no consumer: the
     * editor received the enum's own order for every book, so the field's
     * own docblock ("decides what a full review runs first") described
     * something nothing did.
     *
     * Ordering, never filtering. Every agent stays available and runnable;
     * a memoir author who wants the accessibility check should not have to
     * change the book's type to reach it. The ones that matter for this book
     * are simply first, and marked so the panel can say why.
     *
     * @param  array<int, string>  $preferred
     * @return array<int, array<string, mixed>>
     */
    public static function catalogue(array $preferred = []): array
    {
        $rank = array_flip($preferred);

        $agents = array_map(fn (self $a) => [
            'value' => $a->value,
            'label' => $a->label(),
            'description' => $a->description(),
            'specialist' => $a->isSpecialist(),
            'minimum_tier' => $a->minimumTier(),
            'cost' => config("billing.costs.{$a->costLabel()}"),
            'suited' => isset($rank[$a->value]),
        ], self::cases());

        // Stable within each group: preferred agents in the order the type
        // named them, then everything else in the enum's order. usort is not
        // guaranteed stable before PHP 8.0 and this relies on it, so the
        // comparison falls back to the enum position rather than returning 0.
        $position = array_flip(array_column(self::cases(), 'value'));

        usort($agents, function (array $a, array $b) use ($rank, $position) {
            $aRank = $rank[$a['value']] ?? PHP_INT_MAX;
            $bRank = $rank[$b['value']] ?? PHP_INT_MAX;

            return $aRank <=> $bRank
                ?: $position[$a['value']] <=> $position[$b['value']];
        });

        return $agents;
    }
}
