<?php

use App\Models\ManuscriptSection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs word counts left wrong by the scaffold and by editions.
 *
 * Edition chapters were created with the source's content and a count of 0,
 * and neither the scaffold nor an edition recalculated the book's total, so
 * the shelf showed "0 words" for books that have text. Sections at 0 with
 * content are recounted from their content; every book's total is then set
 * to the sum of its live sections, as Book::recalculateWordCount() does.
 * Content and updated_at are not touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('manuscript_sections')
            ->where('word_count', 0)
            ->whereNotNull('content')
            ->select(['id', 'content'])
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $count = ManuscriptSection::countWords(json_decode((string) $row->content, true) ?: null);

                    if ($count > 0) {
                        DB::table('manuscript_sections')->where('id', $row->id)->update(['word_count' => $count]);
                    }
                }
            });

        DB::statement(<<<'SQL'
            UPDATE books SET word_count = COALESCE((
                SELECT SUM(s.word_count) FROM manuscript_sections s
                WHERE s.book_id = books.id AND s.deleted_at IS NULL
            ), 0)
        SQL);
    }

    public function down(): void
    {
        // A count is derived data: there is nothing meaningful to restore.
    }
};
