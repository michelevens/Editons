<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable version snapshots — see ADR-0005.
 *
 * Rows here are never updated and never deleted. "Immutable versions" and
 * "rollback" are named requirements in CLAUDE.md, and an append-only table is
 * the simplest thing that actually satisfies them.
 *
 * Full snapshots rather than stored operations: an operation log is smaller
 * but a single corrupt or missing op makes every later version
 * unreconstructable. At book scale a 100k-word manuscript is roughly 600 KB
 * of JSON, so a hundred versions is tens of megabytes per book — acceptable
 * now, with a retention policy owed at Milestone 6.
 *
 * NO soft deletes and NO updated_at, on purpose. Both would imply these rows
 * change. They do not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manuscript_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('manuscript_section_id')
                ->constrained()->cascadeOnDelete();

            // Who caused the snapshot. Nullable and nullOnDelete: history
            // must survive the departure of its author, and an orphaned
            // version is better than a deleted one.
            $table->foreignId('user_id')->nullable()
                ->constrained()->nullOnDelete();

            // Monotonic per section, assigned in a transaction. Not a global
            // sequence — "version 7 of chapter 3" is what a human asks for.
            $table->unsignedInteger('version_number');

            $table->string('title');
            $table->jsonb('content')->nullable();
            $table->unsignedInteger('word_count')->default(0);

            // autosave | manual | import | restore. How the snapshot came to
            // exist, so the history panel can show meaningful entries rather
            // than an undifferentiated list.
            $table->string('reason')->default('autosave');

            // Optional human label, e.g. "before structural edit".
            $table->string('label')->nullable();

            // created_at only. See the docblock: these rows do not change.
            $table->timestamp('created_at')->useCurrent();

            // Enforces the per-section sequence at the database level, so two
            // concurrent saves cannot both claim version 5.
            $table->unique(['manuscript_section_id', 'version_number']);
            $table->index(['manuscript_section_id', 'created_at']);
        });

        DB::statement(
            "ALTER TABLE manuscript_versions ADD CONSTRAINT manuscript_versions_reason_check
             CHECK (reason IN ('autosave','manual','import','restore'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('manuscript_versions');
    }
};
