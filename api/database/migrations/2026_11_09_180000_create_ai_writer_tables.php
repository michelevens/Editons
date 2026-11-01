<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI writer (ADR — AI writes the book).
 *
 * - books.ai_plan: the brief, the proposed or accepted plan, and how it
 *   was made (writer, model, cost). Null for every existing book.
 * - manuscript_sections.plan_key ties a binder section to its plan entry;
 *   ai_draft_state is 'unreviewed' from the moment the AI writes it until
 *   the author accepts or edits it.
 * - draft_runs / draft_chapters: one "Write my book" (or one chapter),
 *   each chapter's status, summary, tokens, cost and credits, so a run can
 *   pause, resume, stop at a budget and be retried without paying twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->jsonb('ai_plan')->nullable();
        });

        Schema::table('manuscript_sections', function (Blueprint $table) {
            $table->string('plan_key', 16)->nullable();
            $table->string('ai_draft_state', 16)->nullable();
        });

        Schema::create('draft_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // queued | running | paused | completed | stopped | cancelled | failed
            $table->string('status', 16)->default('queued');
            $table->string('quality', 16)->default('standard');
            $table->string('driver', 40);
            $table->string('model', 120)->nullable();
            // The author's ceiling for this run, in credits; null: the balance.
            $table->unsignedInteger('budget_credits')->nullable();
            $table->unsignedInteger('credits_spent')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->jsonb('estimate')->nullable();
            $table->string('stop_reason', 500)->nullable();
            $table->unsignedInteger('findings')->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['book_id', 'status']);
        });

        Schema::create('draft_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('manuscript_section_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            // write: only into an empty or prompt-only section. rewrite: a new
            // version of a written one, asked for by the author.
            $table->string('mode', 16)->default('write');
            $table->string('note', 1000)->nullable();
            // queued | writing | done | skipped | failed | cancelled
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('words')->default(0);
            $table->unsignedInteger('target_words')->default(0);
            $table->jsonb('summary')->nullable();
            $table->string('model', 120)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('credits')->default(0);
            // The reservation taken before the calls, settled or refunded after.
            $table->foreignId('credit_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manuscript_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('error', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['draft_run_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_chapters');
        Schema::dropIfExists('draft_runs');

        Schema::table('manuscript_sections', function (Blueprint $table) {
            $table->dropColumn(['plan_key', 'ai_draft_state']);
        });

        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('ai_plan');
        });
    }
};
