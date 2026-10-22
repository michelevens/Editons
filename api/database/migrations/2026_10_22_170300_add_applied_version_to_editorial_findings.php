<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which version an accepted fix replaced.
 *
 * Applying a suggestion snapshots the chapter as it was (reason
 * ai_suggestion). Pointing the finding at that snapshot is what lets
 * "Undo fix" put the text back exactly, so an accepted finding only ever
 * leaves the accepted state through a real undo, never by being re-marked
 * while the AI's wording stays in the book.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('editorial_findings', function (Blueprint $table) {
            $table->foreignId('applied_version_id')->nullable()
                ->constrained('manuscript_versions')->nullOnDelete();
            $table->index('applied_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('editorial_findings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('applied_version_id');
        });
    }
};
