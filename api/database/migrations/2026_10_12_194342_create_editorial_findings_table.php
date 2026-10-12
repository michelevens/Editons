<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A single issue an agent raised.
 *
 * Findings are suggestions, never applied edits. CLAUDE.md is explicit:
 * "Human-controlled acceptance of AI edits; never silently rewrite
 * manuscripts." Accepting one is a separate, recorded act — which is why
 * status lives here and why the original excerpt is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_findings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('editorial_review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manuscript_section_id')->constrained()->cascadeOnDelete();

            // Mirrors the review's agent so the panel can group findings
            // without joining, and so a finding survives as evidence if the
            // review row is ever pruned.
            $table->string('agent');

            // critical | warning | suggestion — drives ordering in the panel.
            // An author scanning twenty findings needs the ones that matter
            // first, not the ones that happen to appear earliest in the text.
            $table->string('severity')->default('suggestion');

            $table->string('title');
            $table->text('detail')->nullable();

            // The text the finding is about, copied at review time.
            //
            // Stored rather than referenced because the document moves: an
            // author editing while a review runs would otherwise leave
            // findings pointing at text that no longer exists. Holding the
            // excerpt means a stale finding can be SHOWN as stale instead of
            // silently mismatching.
            $table->text('excerpt')->nullable();

            // Proposed replacement, where the agent offers one. Null for
            // findings that flag without prescribing — a fact-check saying
            // "this claim needs a source" has no text to swap in.
            $table->text('suggestion')->nullable();

            // Character offsets into the section's plain text. Best-effort
            // anchors; the excerpt above is what actually identifies the
            // location when offsets drift. Anchor stability under concurrent
            // editing is an open risk in ARCHITECTURE.md and belongs to
            // Milestone 4.
            $table->unsignedInteger('from_offset')->nullable();
            $table->unsignedInteger('to_offset')->nullable();

            // open | accepted | dismissed
            $table->string('status')->default('open');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['manuscript_section_id', 'status']);
            $table->index(['editorial_review_id']);
        });

        DB::statement(
            "ALTER TABLE editorial_findings ADD CONSTRAINT editorial_findings_severity_check
             CHECK (severity IN ('critical','warning','suggestion'))"
        );

        DB::statement(
            "ALTER TABLE editorial_findings ADD CONSTRAINT editorial_findings_status_check
             CHECK (status IN ('open','accepted','dismissed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_findings');
    }
};
