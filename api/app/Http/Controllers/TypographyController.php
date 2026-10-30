<?php

namespace App\Http\Controllers;

use App\Editorial\Style\Typography;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Services\ManuscriptWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Fix typography": the book's typography rules over every chapter, at the
 * author's request (ADR — Book language and typography).
 *
 * Never a silent rewrite. The preview counts what would change, chapter by
 * chapter, and changes nothing; the fix is a separate request, and each
 * chapter it changes is saved through ManuscriptWriter with reason
 * `typography`, which snapshots the chapter as it was first.
 */
class TypographyController extends Controller
{
    public function __construct(private readonly ManuscriptWriter $writer) {}

    /** What the pass would change, without changing it. */
    public function preview(Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $rules = Typography::resolve($book);
        $chapters = [];

        foreach ($this->sections($book) as $section) {
            [, , $count] = $this->fixed($section, $rules);

            if ($count > 0) {
                $chapters[] = ['id' => $section->id, 'title' => $section->title, 'changes' => $count];
            }
        }

        return response()->json([
            'changes' => array_sum(array_column($chapters, 'changes')),
            'chapters' => $chapters,
        ]);
    }

    /** Apply the pass, a version of each changed chapter taken first. */
    public function fix(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $rules = Typography::resolve($book);
        $chapters = [];

        foreach ($this->sections($book)->pluck('id') as $id) {
            // Read and written under the chapter's lock, so an autosave
            // landing meanwhile is corrected rather than overwritten.
            $done = DB::transaction(function () use ($id, $rules, $request) {
                $section = ManuscriptSection::lockForUpdate()->find($id);

                if (! $section) {
                    return null;
                }

                [$content, $title, $count] = $this->fixed($section, $rules);

                if ($count === 0) {
                    return null;
                }

                $this->writer->save(
                    section: $section,
                    content: $content,
                    title: $title,
                    reason: 'typography',
                    userId: $request->user()->id,
                    label: 'Before typography fix ('.$count.' '.($count === 1 ? 'correction' : 'corrections').')',
                );

                return ['id' => $section->id, 'title' => $title, 'changes' => $count];
            });

            if ($done) {
                $chapters[] = $done;
            }
        }

        return response()->json([
            'changes' => array_sum(array_column($chapters, 'changes')),
            'chapters' => $chapters,
        ]);
    }

    /** Every section with words of its own, in the binder's order. */
    private function sections(Book $book)
    {
        return $book->sections()->orderBy('position')->get()->reject(fn (ManuscriptSection $s) => $s->isContainer() && ! $s->content);
    }

    /** @return array{0: array|null, 1: string, 2: int} content, title, corrections */
    private function fixed(ManuscriptSection $section, array $rules): array
    {
        $count = 0;
        $title = Typography::text((string) $section->title, $rules, $count);
        $content = Typography::document($section->content, $rules, $count);

        return [$content, $title, $count];
    }
}
