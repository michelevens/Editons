<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Citation;
use App\Models\ResearchSource;
use App\Platform\Trust\MasterData;
use App\Research\Bibliography;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The bibliography, and where each source is actually cited.
 *
 * The second half is the part worth having. A list of sources says what an
 * author collected; a list of sources nothing cites says which ones they
 * collected and never used, which is the question that matters before
 * publication.
 */
class ReferencesController extends Controller
{
    public function __construct(private readonly Bibliography $bibliography) {}

    public function show(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        // A style asked for previews it; without one, the book's own choice.
        $style = Bibliography::normalise(
            $request->string('style')->toString() ?: $book->settings()['citation_style'],
        );

        return response()->json([
            'styles' => Bibliography::STYLES,
            // The styles the owner shows (App\Platform\Trust\MasterData),
            // and always the book's own.
            'options' => MasterData::citationOptions(Bibliography::normalise($book->settings()['citation_style'])),
            'style' => $style,
            'saved_style' => Bibliography::normalise($book->settings()['citation_style']),
            'entries' => $this->bibliography->forBook($book, $style),
            'text' => $this->bibliography->asText($book, $style),
            'usage' => $this->usage($book),
        ]);
    }

    /**
     * Make a style the book's own.
     *
     * Separate from previewing: an author comparing APA with Chicago should
     * not change what the export uses by looking.
     */
    public function style(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'style' => ['required', 'string', 'in:'.implode(',', Bibliography::STYLES)],
        ]);

        $book->update(['settings' => array_merge($book->settings(), ['citation_style' => $data['style']])]);

        return $this->show($request->merge(['style' => $data['style']]), $book);
    }

    /**
     * Which chapters cite each source.
     *
     * Read from the citations table rather than by searching chapter text for
     * a marker. A citation records the claim it supports, so this answers
     * "where is this source doing work" instead of "where does this string
     * appear" — and it keeps working when an author rewrites the sentence.
     *
     * @return array<int, array<string, mixed>>
     */
    private function usage(Book $book): array
    {
        $sectionTitles = $book->sections()->pluck('title', 'id');

        $citations = Citation::whereIn('manuscript_section_id', $sectionTitles->keys())
            ->whereNotNull('research_source_id')
            ->get()
            ->groupBy('research_source_id');

        return $book->sources()
            ->orderBy('title')
            ->get()
            ->map(function (ResearchSource $source) use ($citations, $sectionTitles) {
                $used = $citations->get($source->id, collect());

                return [
                    'id' => $source->id,
                    'title' => $source->title,
                    'reference' => $source->reference(),
                    'verification' => $source->verification,
                    'cited_in' => $used
                        ->map(fn (Citation $citation) => [
                            'section_id' => $citation->manuscript_section_id,
                            'section_title' => $sectionTitles[$citation->manuscript_section_id] ?? 'Unknown',
                            'claim' => $citation->claim,
                        ])
                        ->unique('section_id')
                        ->values()
                        ->all(),
                    'citation_count' => $used->count(),
                ];
            })
            ->all();
    }
}
