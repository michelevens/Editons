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
     * @param  array<int, array{title: string, doc: array<string, mixed>}>  $chapters
     */
    public function append(Book $book, array $chapters, ?int $userId, ImportReport $report): void
    {
        if ($chapters === []) {
            return;
        }

        DB::transaction(function () use ($book, $chapters, $userId, $report) {
            // Append after whatever is already in the binder rather than
            // replacing it: import must never destroy existing work.
            $position = (int) $book->sections()->max('position');

            foreach ($chapters as $chapter) {
                $position += ManuscriptSection::POSITION_STEP;

                $section = $book->sections()->create([
                    'type' => 'chapter',
                    'title' => mb_substr($chapter['title'], 0, 255),
                    'position' => $position,
                ]);

                // Through the normal writer so the import is versioned like
                // any other write, and is therefore revertible.
                $this->writer->save(
                    section: $section,
                    content: $chapter['doc'],
                    reason: 'import',
                    userId: $userId,
                );

                $report->sections++;
                $report->titles[] = $section->title;
            }
        });

        $book->refresh()->recalculateWordCount();
    }
}
