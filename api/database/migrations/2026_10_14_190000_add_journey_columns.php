<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The writing journey.
 *
 * A book now knows what kind of book it is, how it was started, how much help
 * the author wants, and where they are. Four columns rather than four tables:
 * each is a single value read on every page of the studio, and none of them
 * is a list.
 *
 * `genre` already exists as free text and stays, because it is what an author
 * types on a store listing. `book_type` is the structural answer that drives
 * templates and checks, and the two are deliberately not the same field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // A key into config/booktypes.php. Nullable, because every book
            // written before this existed has no type and must still open.
            $table->string('book_type')->nullable()->after('genre');

            // How this book was begun: template, idea, import, outline,
            // research, interview, dictation. Kept because the journey
            // offers different next steps depending on where you came in,
            // and because "several methods on one book" needs a record of
            // the first one.
            $table->string('started_with')->nullable()->after('book_type');

            // author_led | coach | collaborative
            $table->string('assistance')->default('author_led')->after('started_with');

            // discover | plan | create | improve | design | publish
            $table->string('journey_stage')->default('discover')->after('assistance');

            // Which journey tasks are done. jsonb for the same reason the
            // launch tasks are: a fixed, small set read as a whole.
            $table->jsonb('journey_tasks')->nullable()->after('journey_stage');

            // Where the author stopped, so resuming returns them there
            // rather than to the top of the binder.
            $table->foreignId('last_section_id')->nullable()->after('journey_tasks')
                ->constrained('manuscript_sections')->nullOnDelete();
        });

        DB::statement(
            "ALTER TABLE books ADD CONSTRAINT books_assistance_check
             CHECK (assistance IN ('author_led','coach','collaborative'))"
        );

        DB::statement(
            "ALTER TABLE books ADD CONSTRAINT books_journey_stage_check
             CHECK (journey_stage IN ('discover','plan','create','improve','design','publish'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE books DROP CONSTRAINT IF EXISTS books_assistance_check');
        DB::statement('ALTER TABLE books DROP CONSTRAINT IF EXISTS books_journey_stage_check');

        Schema::table('books', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_section_id');
            $table->dropColumn([
                'book_type', 'started_with', 'assistance', 'journey_stage', 'journey_tasks',
            ]);
        });
    }
};
