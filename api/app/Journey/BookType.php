<?php

namespace App\Journey;

use InvalidArgumentException;

/**
 * What kind of book somebody is writing.
 *
 * A thin reader over config/booktypes.php, so the configuration stays the
 * single source and nothing has to parse arrays by hand. Adding a genre is an
 * entry in that file, per the directive's "extensible configuration system
 * rather than hardcoding every genre".
 *
 * The length ranges it reports are published trade norms, not market
 * research. Every method that returns one says so, and the UI repeats it,
 * because a precise number invented from nothing is worse than a range an
 * author can argue with.
 */
class BookType
{
    private function __construct(
        public readonly string $key,
        private readonly array $config,
    ) {}

    public static function find(?string $key): ?self
    {
        if (! $key) {
            return null;
        }

        $config = config("booktypes.{$key}");

        return $config ? new self($key, $config) : null;
    }

    public static function findOrFail(string $key): self
    {
        return self::find($key)
            ?? throw new InvalidArgumentException("There is no book type [{$key}].");
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return array_map(
            fn (string $key) => self::findOrFail($key),
            array_keys(config('booktypes', [])),
        );
    }

    /** The print trim this type opens on, when it differs from the default. */
    public function trim(): ?string
    {
        return $this->config['trim'] ?? null;
    }

    public function name(): string
    {
        return $this->config['name'];
    }

    public function blurb(): string
    {
        return $this->config['blurb'];
    }

    /** @return array{0: int, 1: int} typical total words */
    public function words(): array
    {
        return $this->config['words'];
    }

    /** @return array{0: int, 1: int} typical chapter count */
    public function chapters(): array
    {
        return $this->config['chapters'];
    }

    /**
     * The middle of the typical range, as the form's starting value.
     *
     * A default the author immediately changes is still more useful than an
     * empty box, because it shows the shape of the answer expected.
     */
    public function suggestedWords(): int
    {
        [$low, $high] = $this->words();

        // Rounded to the nearest thousand: a target of 64,173 implies a
        // precision that does not exist.
        return (int) (round(($low + $high) / 2 / 1000) * 1000);
    }

    public function suggestedChapters(): int
    {
        [$low, $high] = $this->chapters();

        return (int) round(($low + $high) / 2);
    }

    public function usesParts(): bool
    {
        return (bool) ($this->config['parts'] ?? false);
    }

    /** @return array<int, string> */
    public function frontMatter(): array
    {
        return $this->config['front_matter'] ?? [];
    }

    /** @return array<int, string> */
    public function backMatter(): array
    {
        return $this->config['back_matter'] ?? [];
    }

    /**
     * The reviewers that matter most for this kind of book.
     *
     * Not a restriction: every agent remains available. This decides what a
     * full review runs first and what the journey suggests.
     *
     * @return array<int, string>
     */
    public function agents(): array
    {
        return $this->config['agents'] ?? [];
    }

    /**
     * The three Discover questions, in this genre's own terms.
     *
     * The fields are the same everywhere — premise, audience, promise — and
     * only the wording changes. A novelist and a cookbook author are both
     * answering "what is this", but "What happens, and to whom?" and "What
     * ties these recipes together?" are not the same question, and a generic
     * "What is it about?" gets a generic answer.
     *
     * Falls back to the neutral phrasing, so a type that omits them still
     * asks something sensible rather than nothing.
     *
     * @return array{premise: string, audience: string, promise: string}
     */
    public function questions(): array
    {
        return array_merge(self::NEUTRAL_QUESTIONS, $this->config['questions'] ?? []);
    }

    /** What Discover asks when the book has no type yet. */
    public const NEUTRAL_QUESTIONS = [
        'premise' => 'What is it about?',
        'audience' => 'Who is it for?',
        'promise' => 'What does the reader get?',
    ];

    /** The line each scaffolded chapter opens with. */
    public function chapterPrompt(): string
    {
        return $this->config['chapter_prompt'] ?? 'What happens in this chapter?';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name(),
            'blurb' => $this->blurb(),
            'words' => $this->words(),
            'chapters' => $this->chapters(),
            'suggested_words' => $this->suggestedWords(),
            'suggested_chapters' => $this->suggestedChapters(),
            'uses_parts' => $this->usesParts(),
            'front_matter' => $this->frontMatter(),
            'back_matter' => $this->backMatter(),
            'agents' => $this->agents(),
            'questions' => $this->questions(),
        ];
    }
}
