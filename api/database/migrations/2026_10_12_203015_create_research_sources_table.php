<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A source the author has gathered for a book.
 *
 * ARCHITECTURE.md design rule 3 requires AI grounded in "manuscript and
 * approved research" with "explicit provenance". That word approved is the
 * reason for the verification columns below: a source the author has not
 * vouched for must not be presented as evidence, and the system has to be
 * able to tell the difference.
 *
 * Sources belong to a book rather than to a user because the same author
 * citing the same paper in two books is doing two separate pieces of
 * research; merging them would let an edit to one silently change the
 * other's bibliography.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_sources', function (Blueprint $table) {
            $table->id();

            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();

            // book | journal_article | website | report | interview | other
            $table->string('kind')->default('other');

            $table->string('title');
            $table->string('authors')->nullable();
            $table->string('publisher')->nullable();

            // Free text, not a date: sources carry "2019", "Spring 2020" and
            // "n.d." as often as they carry a full date, and coercing those
            // into a timestamp invents precision the source does not have.
            $table->string('published')->nullable();

            $table->text('url')->nullable();
            $table->string('doi')->nullable();
            $table->string('isbn')->nullable();

            // The author's own summary of what this source supports.
            $table->text('notes')->nullable();

            // unverified | verified | disputed
            //
            // ARCHITECTURE.md lists "citations that cannot be independently
            // verified" as a risk to resolve. It is resolved by refusing to
            // paper over it: a source states its standing, and a citation
            // resting on an unverified source is shown as exactly that.
            $table->string('verification')->default('unverified');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['book_id', 'verification']);
        });

        DB::statement(
            "ALTER TABLE research_sources ADD CONSTRAINT research_sources_kind_check
             CHECK (kind IN ('book','journal_article','website','report','interview','other'))"
        );

        DB::statement(
            "ALTER TABLE research_sources ADD CONSTRAINT research_sources_verification_check
             CHECK (verification IN ('unverified','verified','disputed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('research_sources');
    }
};
