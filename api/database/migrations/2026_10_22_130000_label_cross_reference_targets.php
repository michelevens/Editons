<?php

use App\Publishing\Numbering;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Labels every caption, numbered equation and heading that has none yet.
 *
 * Labels used to be given lazily by GET /books/{book}/cross-references,
 * which wrote back other chapters from a copy it had read and could undo an
 * autosave that landed in between. They are now given when content is saved
 * (ManuscriptWriter::save); this gives them once to everything saved before
 * that, so the cross-reference picker still offers every target.
 *
 * Each row is re-read under its own lock, so a save running during the
 * deploy is never overwritten. Only attributes change; the text, word count,
 * versions and updated_at are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('manuscript_sections')->select('id')->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row) {
                        $content = DB::table('manuscript_sections')->where('id', $row->id)->lockForUpdate()->value('content');
                        $labelled = Numbering::label(is_string($content) ? json_decode($content, true) : null);

                        if ($labelled) {
                            DB::table('manuscript_sections')->where('id', $row->id)
                                ->update(['content' => json_encode($labelled)]);
                        }
                    });
                }
            });
    }

    public function down(): void
    {
        // Labels are harmless to keep, and references may already point at them.
    }
};
