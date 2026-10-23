<?php

namespace App\Services;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\ManuscriptVersion;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Words written per day, from version history — the one source of truth.
 *
 * The Analytics chart and the writing goals (today's words, the streak)
 * both read this, so the two can never disagree about what somebody wrote.
 * Nothing here is estimated or kept in a counter of its own: every save
 * writes a version carrying a word count and a timestamp, and a day's
 * figure is the net change those versions record.
 *
 * Days are the application's calendar days (config app.timezone), the same
 * days the Analytics chart labels.
 */
final class WritingProgress
{
    /** How far back a streak is looked for. A longer one is "at least". */
    public const STREAK_WINDOW = 365;

    /**
     * Words written per day over the last $days days, oldest first.
     *
     * A day's figure is the net change in the sections' size across that
     * day — the difference between the last snapshot of each chapter that
     * day and the one before it. That makes deletions count as negative,
     * which is correct: an author who cut four thousand words did not write
     * four thousand.
     *
     * @param  Collection<int, int>|array<int, int>  $sectionIds
     * @return array<int, array{date: string, label: string, words: int, today: bool}>
     */
    public static function daily(Collection|array $sectionIds, int $days): array
    {
        $sectionIds = collect($sectionIds)->values();
        $since = now()->subDays($days - 1)->startOfDay();

        $buckets = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = now()->subDays($offset)->startOfDay();
            $buckets[$date->toDateString()] = [
                'date' => $date->toDateString(),
                'label' => $date->format('M j'),
                'words' => 0,
                'today' => $offset === 0,
            ];
        }

        if ($sectionIds->isEmpty()) {
            return array_values($buckets);
        }

        $versions = ManuscriptVersion::whereIn('manuscript_section_id', $sectionIds)
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->get(['manuscript_section_id', 'word_count', 'created_at']);

        // Where each chapter stood before the window opened, so the first
        // day's change is measured against reality rather than from zero.
        // One query for every chapter (it was one per chapter): the newest
        // snapshot of each before the window, served by the
        // (manuscript_section_id, created_at) index.
        $baseline = ManuscriptVersion::whereIn('manuscript_section_id', $sectionIds)
            ->where('created_at', '<', $since)
            ->selectRaw('DISTINCT ON (manuscript_section_id) manuscript_section_id, word_count')
            ->orderBy('manuscript_section_id')
            ->orderByDesc('created_at')
            ->orderByDesc('version_number')
            ->pluck('word_count', 'manuscript_section_id')
            ->map(fn ($words) => (int) $words)
            ->all();

        foreach ($versions as $version) {
            $key = $version->created_at->toDateString();

            if (! isset($buckets[$key])) {
                continue;
            }

            $previous = $baseline[$version->manuscript_section_id] ?? 0;

            $buckets[$key]['words'] += $version->word_count - $previous;
            $baseline[$version->manuscript_section_id] = $version->word_count;
        }

        // ManuscriptWriter snapshots the state BEFORE a write, so the newest
        // text is never in a version yet. Charting versions alone would
        // permanently omit whatever the author wrote most recently — the work
        // they most want to see — so the live sections close the gap.
        $today = now()->toDateString();

        if (isset($buckets[$today])) {
            // Counts only: the content of every chapter is not needed here.
            foreach (ManuscriptSection::whereIn('id', $sectionIds)->pluck('word_count', 'id') as $id => $words) {
                $buckets[$today]['words'] += (int) $words - ($baseline[$id] ?? 0);
            }
        }

        return array_values($buckets);
    }

    /**
     * The sections whose writing counts toward somebody's daily goal: every
     * section of the books they own and have not archived — the same books
     * the weekly digest (App\Mail\Support\WeeklyProgress) reports on.
     *
     * Books shared with them are left out on purpose. Versions record the
     * size of a chapter, not who typed which word, so an editor's day would
     * otherwise be credited with the owner's writing.
     *
     * @return Collection<int, int>
     */
    public static function sectionsOf(User $user): Collection
    {
        return ManuscriptSection::query()
            ->whereIn('book_id', $user->books()->where('status', '!=', 'archived')->select('id'))
            ->pluck('id');
    }

    /**
     * Today's words, the daily goal and the streak, for one person.
     *
     * A day "counts" toward the streak when it reaches the daily goal, or —
     * with no goal set — when any words were written. A streak that has not
     * been extended today yet is still alive: it runs to yesterday until the
     * day is over.
     *
     * @return array<string, mixed>
     */
    public static function forUser(User $user): array
    {
        $ids = self::sectionsOf($user);
        $goal = $user->daily_word_goal ? (int) $user->daily_word_goal : null;
        $series = self::daily($ids, self::STREAK_WINDOW);

        $counts = fn (array $day) => $goal ? $day['words'] >= $goal : $day['words'] > 0;

        $today = end($series);
        $todayCounts = $counts($today);

        // Walk back from today (or yesterday, while today is still open).
        $streak = 0;
        $days = array_reverse($series);

        foreach ($todayCounts ? $days : array_slice($days, 1) as $day) {
            if (! $counts($day)) {
                break;
            }

            $streak++;
        }

        return [
            'today' => [
                'words' => (int) $today['words'],
                'goal' => $goal,
                'met' => $goal !== null && $today['words'] >= $goal,
            ],
            'streak' => [
                'days' => $streak,
                'rule' => $goal ? 'goal' : 'any',
                'includes_today' => $todayCounts,
                // Every day of the window counted: the real streak may be longer.
                'at_least' => $streak >= self::STREAK_WINDOW,
            ],
            // Without a single version there is no record of when anything
            // was written, and the figures above are not a history.
            'has_history' => $ids->isNotEmpty()
                && ManuscriptVersion::whereIn('manuscript_section_id', $ids)->exists(),
        ];
    }

    /**
     * The goals sheet for one chapter of a book: the chapter against its
     * goal, the book against its target, and the person's day.
     *
     * @return array<string, mixed>
     */
    public static function goals(User $user, Book $book, ?ManuscriptSection $section): array
    {
        return [
            'chapter' => $section ? [
                'id' => $section->id,
                'title' => $section->title,
                'words' => (int) $section->word_count,
                'goal' => $section->word_goal ? (int) $section->word_goal : null,
            ] : null,
            'book' => [
                'id' => $book->id,
                'words' => (int) $book->word_count,
                'target' => $book->target_words ? (int) $book->target_words : null,
                // Today's words count the person's own books only.
                'owned' => $book->user_id === $user->id,
            ],
            ...self::forUser($user),
        ];
    }
}
