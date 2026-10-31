<?php

namespace App\Import;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Services\ManuscriptWriter;
use Illuminate\Support\Facades\DB;

/**
 * Writes imported chapters into a book's binder.
 *
 * Shared by every importer so that all of them append rather than replace,
 * version through ManuscriptWriter, and keep the word count right. An import
 * is one transaction: half a manuscript in the binder is worse than none.
 */
class ChapterAppender
{
    public function __construct(private readonly ManuscriptWriter $writer) {}

    /**
     * $options carries what the author chose in the import dialog (section
     * types, "written with an AI tool"); without it every section is a
     * chapter saved as an `import`, as always.
     *
     * @param  array<int, array{title: string, doc: array<string, mixed>}>  $chapters
     */
    public function append(Book $book, array $chapters, ?int $userId, ImportReport $report, ?ImportOptions $options = null): void
    {
        if ($chapters === []) {
            return;
        }

        $options ??= new ImportOptions;

        DB::transaction(function () use ($book, $chapters, $userId, $report, $options) {
            // Append after whatever is already in the binder rather than
            // replacing it: import must never destroy existing work.
            $position = (int) $book->sections()->max('position');

            foreach (array_values($chapters) as $index => $chapter) {
                $position += ManuscriptSection::POSITION_STEP;

                $section = $book->sections()->create([
                    'type' => $options->typeAt($index),
                    'title' => mb_substr($chapter['title'], 0, 255),
                    'position' => $position,
                    // A section with no content takes its first save without
                    // a version (ManuscriptWriter). Text written with AI
                    // starts from an empty page instead, so that first save
                    // leaves the `ai_draft` version the disclosure counts —
                    // as the AI drafting tools do.
                    ...($options->aiLabel !== null ? ['content' => ['type' => 'doc', 'content' => []]] : []),
                ]);

                // Through the normal writer so the import is versioned like
                // any other write, and is therefore revertible.
                $this->writer->save(
                    section: $section,
                    content: $chapter['doc'],
                    reason: $options->aiLabel !== null ? 'ai_draft' : 'import',
                    userId: $userId,
                    label: $options->aiLabel,
                );

                $report->sections++;
                $report->titles[] = $section->title;
            }
        });

        $book->refresh()->recalculateWordCount();
    }
}
