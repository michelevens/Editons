<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Manuscript sections — the chapter binder.
 *
 * One table holds every node in the tree: front matter, parts, chapters, back
 * matter. A self-referencing parent_id gives the nesting the design shows
 * (PART I > Chapter 1) without a table per level.
 *
 * `content` is ProseMirror JSON in jsonb — see ADR-0004. Not HTML: design
 * rule 1 in ARCHITECTURE.md requires content stored independently of
 * presentation, and that is what makes one manuscript renderable to DOCX,
 * EPUB and PDF without reverse-engineering intent from markup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manuscript_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();

            // Self-reference for parts containing chapters. Cascade so
            // deleting a part removes its children rather than orphaning them
            // into an inconsistent tree.
            $table->foreignId('parent_id')->nullable()
                ->constrained('manuscript_sections')->cascadeOnDelete();

            // front_matter | part | chapter | section | back_matter
            $table->string('type')->default('chapter');

            $table->string('title');
            $table->string('subtitle')->nullable();

            // ProseMirror document JSON. jsonb rather than text: Postgres can
            // index and query into it, which Milestone 2 needs for
            // manuscript-wide retrieval. Nullable because a part is a
            // container with no body of its own.
            $table->jsonb('content')->nullable();

            // Derived from content on save, same reasoning as books.word_count
            // — the binder shows per-chapter counts on every render.
            $table->unsignedInteger('word_count')->default(0);

            // Sparse ordering (10, 20, 30…) so a chapter can be dropped
            // between two others by picking a midpoint, instead of renumbering
            // every sibling on each drag.
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['book_id', 'parent_id', 'position']);
        });

        DB::statement(
            "ALTER TABLE manuscript_sections ADD CONSTRAINT manuscript_sections_type_check
             CHECK (type IN ('front_matter','part','chapter','section','back_matter'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('manuscript_sections');
    }
};
