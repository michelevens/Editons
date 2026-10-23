<?php

namespace App\Http\Controllers;

use App\Editorial\EditorialAgent;
use App\Models\Book;
use App\Models\EditorialFinding;
use App\Models\ManuscriptSection;
use App\Models\ManuscriptVersion;
use App\Services\WritingProgress;
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
            // The same arithmetic as the writing goals' "today" and streak.
            'daily' => WritingProgress::daily($sectionIds, $days),
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
