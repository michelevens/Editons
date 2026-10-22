<?php

namespace App\Http\Controllers;

use App\Editorial\EditorialAgent;
use App\Models\Book;
use App\Models\EditorialFinding;
use App\Models\ManuscriptSection;
use App\Models\ManuscriptVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Writing progress, computed from version history.
 *
 * The prototype generates its daily chart from a seeded random number and
 * labels it "sample history". It does not have to be: every save writes a
 * version carrying a word count and a timestamp, so the real curve is
 * already recorded. Inventing one here would be the mock-data trap when the
 * truth costs a single query.
 *
 * The honest limitation is at the start — a book written before versioning,
 * or imported in one go, has a flat history and a single spike. The screen
 * says so rather than drawing a plausible-looking line.
 */
class AnalyticsController extends Controller
{
    public function show(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        $days = max(7, min(90, $request->integer('days') ?: 14));
        $sectionIds = $book->sections()->pluck('id');

        $findings = EditorialFinding::whereIn('manuscript_section_id', $sectionIds)->get();

        return response()->json([
            'days' => $days,
            'daily' => $this->daily($sectionIds, $days),
            'by_section' => $this->bySection($book),
            'by_agent' => $this->byAgent($findings),
            'totals' => [
                'words' => $book->word_count,
                'target' => $book->target_words,
                'chapters' => $book->sections()->where('type', '!=', 'part')->count(),
                'versions' => ManuscriptVersion::whereIn('manuscript_section_id', $sectionIds)->count(),
                'findings_open' => $findings->where('status', 'open')->count(),
                'findings_applied' => $findings->where('status', 'accepted')->count(),
                'findings_dismissed' => $findings->where('status', 'dismissed')->count(),
            ],
            // Whether the daily chart can be trusted as a record of writing,
            // rather than of one import.
            'has_history' => ManuscriptVersion::whereIn('manuscript_section_id', $sectionIds)
                ->distinct()
                ->count('manuscript_section_id') > 0,
        ]);
    }

    /**
     * Words written per day, from version snapshots.
     *
     * A day's figure is the net change in the book's size across that day —
     * the difference between the last snapshot of each chapter that day and
     * the one before it. That makes deletions count as negative, which is
     * correct: an author who cut four thousand words did not write four
     * thousand.
     *
     * @return array<int, array<string, mixed>>
     */
    private function daily($sectionIds, int $days): array
    {
        $since = now()->subDays($days - 1)->startOfDay();

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

    /** @return array<int, array<string, mixed>> */
    private function bySection(Book $book): array
    {
        return $book->sections()
            ->where('type', '!=', 'part')
            ->orderBy('position')
            ->get(['id', 'title', 'word_count', 'status'])
            ->map(fn (ManuscriptSection $section) => [
                'id' => $section->id,
                'title' => $section->title,
                'words' => $section->word_count,
                'status' => $section->status,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function byAgent($findings): array
    {
        return collect(EditorialAgent::catalogue())
            ->map(fn (array $agent) => [
                'value' => $agent['value'],
                'label' => $agent['label'],
                'open' => $findings->where('agent.value', $agent['value'])
                    ->where('status', 'open')
                    ->count(),
            ])
            ->all();
    }
}
