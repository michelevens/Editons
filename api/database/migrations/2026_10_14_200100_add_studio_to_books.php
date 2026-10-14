<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which studio a book belongs to.
 *
 * Author Studio covers books: fiction, nonfiction, memoir, professional.
 * Academic Studio covers theses, dissertations and capstone projects, and is
 * not built yet.
 *
 * Split from `book_type` now rather than later. Academic work needs a
 * discipline (nursing, education, business) crossed with a document type
 * (DNP project, PhD dissertation, master's thesis), and those vary
 * independently — one flat key cannot express the pair without inventing
 * compound values like `academic_nursing_dnp`, which then have to be parsed
 * by string. Doing this before there are books in the wild is a column.
 * Doing it after is a backfill plus a guess about which existing value meant
 * which.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('studio')->default('author')->after('genre');
        });

        DB::statement(
            "ALTER TABLE books ADD CONSTRAINT books_studio_check
             CHECK (studio IN ('author','academic'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE books DROP CONSTRAINT IF EXISTS books_studio_check');

        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('studio');
        });
    }
};
