<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Books.
 *
 * A book is the unit an author owns and works on. Editions, layout templates
 * and products hang off it later (ARCHITECTURE.md lists them); none of that
 * exists yet and this table does not pretend otherwise.
 *
 * No tenant_id — see ADR-0006. Ownership is user_id and every query filters on
 * it, so adding tenancy later is a column plus a backfill, not a redesign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();

            // Cascade: deleting an author removes their books. Deliberate while
            // there is no sharing. Revisit at Milestone 4, where a book can
            // have collaborators and an owner leaving should not destroy it.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('author_name')->nullable();

            // Free text, not an enum: "Nonfiction", "Memoir", "Technical" are
            // author-facing labels, and constraining them buys nothing while a
            // new genre would need a migration.
            $table->string('genre')->nullable();

            // A CHECK constraint rather than a Postgres enum type: altering an
            // enum needs a migration dance, a CHECK is a one-line change.
            $table->string('status')->default('draft');

            $table->text('description')->nullable();

            // Denormalised, recomputed on save. Word count is read on every
            // list view; deriving it from each section's JSON every time would
            // make the dashboard O(sections).
            $table->unsignedInteger('word_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'updated_at']);
        });

        DB::statement(
            "ALTER TABLE books ADD CONSTRAINT books_status_check
             CHECK (status IN ('draft','in_review','published'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
