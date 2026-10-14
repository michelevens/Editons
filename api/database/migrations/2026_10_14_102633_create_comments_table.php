<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Threaded comments on a passage of the manuscript.
 *
 * Anchored by a copied excerpt, the same way editorial findings and citations
 * already are. Character offsets drift the moment anyone types above the
 * passage, and this application has no operational-transform layer to keep
 * them honest — so the excerpt IS the anchor, and a comment whose text has
 * gone is shown as detached rather than silently pointing at the wrong
 * sentence.
 *
 * Replies are rows in this table with a parent, not a separate structure. A
 * reply is a comment that happens to be inside a thread, and two tables would
 * mean writing every query twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('manuscript_section_id')->constrained()->cascadeOnDelete();

            // Null for a top-level comment. A reply carries no anchor of its
            // own — it belongs to whatever its parent is attached to.
            $table->foreignId('parent_id')->nullable()
                ->constrained('comments')->cascadeOnDelete();

            // Kept even if the account is deleted, so a thread does not lose
            // its shape. The name is denormalised for the same reason.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name');

            $table->text('body');

            // The passage this is about, copied when the comment was made.
            $table->text('excerpt')->nullable();

            // open | resolved
            $table->string('status')->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['manuscript_section_id', 'status']);
            $table->index(['parent_id']);
        });

        DB::statement(
            "ALTER TABLE comments ADD CONSTRAINT comments_status_check
             CHECK (status IN ('open','resolved'))"
        );

        // A reply cannot carry its own anchor or its own resolution: it is
        // part of a thread, and the thread is what gets resolved. Without
        // this, a resolved reply inside an open thread is representable and
        // nothing would agree on what it means.
        DB::statement(
            "ALTER TABLE comments ADD CONSTRAINT comments_reply_shape_check
             CHECK (parent_id IS NULL OR (excerpt IS NULL AND status = 'open'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
