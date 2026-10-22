<?php

use App\Publishing\BookPart;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Names the conventional part of every existing section from its title.
 *
 * Only `part` is written: the type, title, content, position and updated_at
 * of every section stay exactly as they were. A title that matches nothing
 * leaves the part null for the author to set in the binder. The same
 * inference runs on demand with `php artisan books:infer-parts`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('manuscript_sections')
            ->join('books', 'books.id', '=', 'manuscript_sections.book_id')
            ->whereNull('manuscript_sections.part')
            ->select(['manuscript_sections.id', 'manuscript_sections.title', 'manuscript_sections.type', 'books.language'])
            ->orderBy('manuscript_sections.id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $part = BookPart::infer($row->title, $row->type, $row->language);

                    if ($part) {
                        DB::table('manuscript_sections')->where('id', $row->id)->update(['part' => $part->value]);
                    }
                }
            }, 'manuscript_sections.id', 'id');
    }

    public function down(): void
    {
        DB::table('manuscript_sections')->update(['part' => null]);
    }
};
