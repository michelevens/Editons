<?php

use App\Publishing\BookPart;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which conventional part of a book a section is: preface, epilogue, index…
 *
 * Alongside `type`, not instead of it. `type` is the coarse class the editor,
 * numbering and renderers have always read (front_matter, part, chapter,
 * section, back_matter); `part` is the Chicago name (App\Publishing\BookPart).
 * Nullable: a sub-section, or front matter nobody has named ("Declaration"),
 * has no conventional part, and guessing one would be worse than saying so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manuscript_sections', function (Blueprint $table) {
            $table->string('part', 40)->nullable()->after('type');
        });

        $allowed = collect(BookPart::sectionKinds())
            ->map(fn (BookPart $p) => "'".$p->value."'")
            ->implode(',');

        DB::statement(
            "ALTER TABLE manuscript_sections ADD CONSTRAINT manuscript_sections_part_check
             CHECK (part IS NULL OR part IN ({$allowed}))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE manuscript_sections DROP CONSTRAINT IF EXISTS manuscript_sections_part_check');

        Schema::table('manuscript_sections', function (Blueprint $table) {
            $table->dropColumn('part');
        });
    }
};
