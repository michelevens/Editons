<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the Overview and Review Workflow screens need.
 *
 * The prototype's Overview reads a word target and a per-chapter status; its
 * Review Workflow is a kanban over that same status. Neither existed, so both
 * screens would have had nothing real to render.
 *
 * Deliberately two columns and not a planning subsystem: a target is one
 * number an author sets, and a chapter's state is one of four values. Modelling
 * either as its own table would be guessing at a shape nothing has asked for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // Nullable rather than defaulted: a book with no target is a real
            // state, and showing "0% of 0 words" would be worse than showing
            // nothing. The UI asks for one instead.
            $table->unsignedInteger('target_words')->nullable()->after('word_count');
        });

        Schema::table('manuscript_sections', function (Blueprint $table) {
            // draft | review | revisions | approved — the prototype's ORDER.
            $table->string('status')->default('draft')->after('word_count');

            // Who is reviewing it, for the workflow board's assignment select.
            $table->foreignId('reviewer_id')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
        });

        DB::statement(
            "ALTER TABLE manuscript_sections ADD CONSTRAINT manuscript_sections_status_check
             CHECK (status IN ('draft','review','revisions','approved'))"
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE manuscript_sections DROP CONSTRAINT IF EXISTS manuscript_sections_status_check'
        );

        Schema::table('manuscript_sections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewer_id');
            $table->dropColumn('status');
        });

        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('target_words');
        });
    }
};
