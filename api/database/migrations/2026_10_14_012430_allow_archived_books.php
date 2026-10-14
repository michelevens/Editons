<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Archiving is a status, not a soft delete.
 *
 * The prototype's My Books filters on Active / Archived and restores from the
 * same list, so an archived book is still the author's and still readable —
 * which soft deletion does not model. The CHECK constraint has to learn the
 * new value or every archive attempt fails at the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE books DROP CONSTRAINT IF EXISTS books_status_check');
        DB::statement(
            "ALTER TABLE books ADD CONSTRAINT books_status_check
             CHECK (status IN ('draft','in_review','published','archived'))"
        );
    }

    public function down(): void
    {
        // Nothing may be left on the value the old constraint forbids.
        DB::table('books')->where('status', 'archived')->update(['status' => 'draft']);

        DB::statement('ALTER TABLE books DROP CONSTRAINT IF EXISTS books_status_check');
        DB::statement(
            "ALTER TABLE books ADD CONSTRAINT books_status_check
             CHECK (status IN ('draft','in_review','published'))"
        );
    }
};
