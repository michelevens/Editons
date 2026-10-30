<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Organising a research library, and where each entry came from.
 *
 * Tags are many-per-source ("primary", "ch. 3", "to read"); a collection is
 * the one shelf a source sits on ("Thesis — methods"). Both live on the
 * source row because sources belong to one book (see the create migration):
 * a tag table keyed by book would only re-state that ownership.
 *
 * `origin` records how the entry arrived — typed in, a BibTeX or RIS file,
 * or a Crossref DOI lookup — so an imported record is never mistaken for one
 * the author wrote down themselves. It says nothing about verification: an
 * imported entry is unverified until somebody verifies it, like any other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_sources', function (Blueprint $table) {
            $table->jsonb('tags')->default('[]');
            $table->string('collection', 80)->nullable();
            // null = typed in | bibtex | ris | crossref
            $table->string('origin', 20)->nullable();

            $table->index(['book_id', 'collection']);
        });
    }

    public function down(): void
    {
        Schema::table('research_sources', function (Blueprint $table) {
            $table->dropIndex(['book_id', 'collection']);
            $table->dropColumn(['tags', 'collection', 'origin']);
        });
    }
};
