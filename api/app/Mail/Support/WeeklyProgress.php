<?php

namespace App\Mail\Support;

use App\Models\Book;
use App\Models\Comment;
use App\Models\EditorialFinding;
use App\Models\ManuscriptSection;
use App\Models\ManuscriptVersion;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * What somebody wrote in a week, from version history.
 *
 * The same arithmetic as the Analytics screen: a chapter's words at the end
 * of the week minus its words at the start, where "the start" is its last
 * version before the week began (or nothing, for a chapter begun this
 * week). Net words, so cutting a bloated scene counts as negative — which
 * is honest, and the email says it gently.
 */
final class WeeklyProgress
{
    /**
     * @return array<string, mixed>|null null when the person has no books
     */
    public static function for(User $user, CarbonInterface $start, CarbonInterface $end): ?array
    {
        $books = $user->books()->where('status', '!=', 'archived')->orderByDesc('updated_at')->get();

        if ($books->isEmpty()) {
            return null;
        }

        $summary = [
            'words' => 0,
            'active_days' => 0,
            'chapters_touched' => 0,
            'open_findings' => 0,
            'open_comments' => 0,
            'books' => [],
        ];
        $days = [];

        foreach ($books as $book) {
            /** @var Book $book */
            $sections = $book->sections()->where('type', '!=', 'part')->get(['id', 'word_count', 'created_at']);
            $ids = $sections->pluck('id');

            $inWeek = ManuscriptVersion::whereIn('manuscript_section_id', $ids)
                ->whereBetween('created_at', [$start, $end])
                ->get(['manuscript_section_id', 'word_count', 'created_at']);

            $added = 0;

            foreach ($sections as $section) {
                /** @var ManuscriptSection $section */
                $before = (int) ManuscriptVersion::where('manuscript_section_id', $section->id)
                    ->where('created_at', '<', $start)
                    ->orderByDesc('created_at')
                    ->value('word_count');

                $atEnd = ManuscriptVersion::where('manuscript_section_id', $section->id)
                    ->where('created_at', '<=', $end)
                    ->orderByDesc('created_at')
                    ->value('word_count');

                // A section with no version by the end of the week, or one
                // created after it, did not change during it.
                if ($atEnd === null) {
                    continue;
                }

                $added += (int) $atEnd - $before;
            }

            foreach ($inWeek as $version) {
                $days[$version->created_at->toDateString()] = true;
            }

            $touched = $inWeek->pluck('manuscript_section_id')->unique()->count();

            $summary['words'] += $added;
            $summary['chapters_touched'] += $touched;
            $summary['open_findings'] += EditorialFinding::whereIn('manuscript_section_id', $ids)->where('status', 'open')->count();
            $summary['open_comments'] += Comment::whereIn('manuscript_section_id', $ids)->whereNull('parent_id')->where('status', 'open')->count();
            $summary['books'][] = [
                'id' => $book->id,
                'title' => $book->title,
                'words_added' => $added,
                'total_words' => (int) $book->word_count,
                'target_words' => $book->target_words ? (int) $book->target_words : null,
                'url' => route('books.edit', $book),
            ];
        }

        $summary['active_days'] = count($days);
        $summary['books'] = array_slice($summary['books'], 0, 5);

        return $summary;
    }
}
