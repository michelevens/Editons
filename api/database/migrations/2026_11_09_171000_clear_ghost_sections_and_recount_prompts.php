<?php

use App\Models\ManuscriptSection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ghost chapters, and scaffold prompts counted as words (ADR — Author
 * walkthrough fixes).
 *
 * - A live section whose parent (or any ancestor) is soft-deleted is
 *   soft-deleted with it, at the parent's time: importing an outline
 *   deleted the scaffold's parts and left their chapters live and
 *   invisible, still counted, reviewed and exported.
 * - A chapter whose only text is the scaffold's prompt counts 0 words, so
 *   Guided no longer says "11 of 11 written" before anything is written.
 *
 * Books touched get their word count recalculated. Nothing is
 * force-deleted, so the down migration has nothing to put back.
 */
return new class extends Migration
{
    public function up(): void
    {
        $books = [];

        // Level by level, until no live section sits under a deleted one.
        for ($pass = 0; $pass < 10; $pass++) {
            $orphans = DB::table('manuscript_sections as child')
                ->join('manuscript_sections as parent', 'parent.id', '=', 'child.parent_id')
                ->whereNull('child.deleted_at')
                ->whereNotNull('parent.deleted_at')
                ->get(['child.id', 'child.book_id', 'parent.deleted_at']);

            if ($orphans->isEmpty()) {
                break;
            }

            foreach ($orphans as $orphan) {
                DB::table('manuscript_sections')->where('id', $orphan->id)->update(['deleted_at' => $orphan->deleted_at]);
                $books[$orphan->book_id] = true;
            }
        }

        // A prompt is at most a sentence or two; a chapter with more words
        // than that has writing in it and keeps its count.
        DB::table('manuscript_sections')
            ->whereNull('deleted_at')
            ->whereBetween('word_count', [1, 40])
            ->orderBy('id')
            ->select(['id', 'book_id', 'content', 'word_count'])
            ->chunkById(500, function ($sections) use (&$books) {
                foreach ($sections as $section) {
                    $count = ManuscriptSection::countWords(json_decode((string) $section->content, true));

                    if ($count !== (int) $section->word_count) {
                        DB::table('manuscript_sections')->where('id', $section->id)->update(['word_count' => $count]);
                        $books[$section->book_id] = true;
                    }
                }
            });

        // Written directly, as Book::recalculateWordCount does, without
        // the model's save events.
        foreach (array_keys($books) as $id) {
            DB::table('books')->where('id', $id)->update([
                'word_count' => (int) DB::table('manuscript_sections')->where('book_id', $id)->whereNull('deleted_at')->sum('word_count'),
            ]);
        }
    }

    public function down(): void
    {
        // Soft deletes and counts only; nothing to restore.
    }
};
