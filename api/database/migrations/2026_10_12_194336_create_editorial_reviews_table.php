<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One run of one editorial agent over one section.
 *
 * A review is a job record, not the findings themselves — it exists from the
 * moment someone asks for a review so the UI can show "queued" and "running"
 * rather than a spinner with no state behind it.
 *
 * Cost and model are recorded per run because CLAUDE.md requires it: "Record
 * prompt/model/version and costs." A platform that bills AI usage cannot
 * reconstruct what it spent after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_reviews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('manuscript_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Which agent ran. See EditorialAgent for the catalogue.
            $table->string('agent');

            // queued | running | completed | failed
            $table->string('status')->default('queued');

            // Which snapshot was reviewed. Findings anchor into a document
            // that may have moved on since; knowing the version they were
            // produced against is what makes a stale finding detectable
            // rather than silently wrong.
            $table->unsignedInteger('section_version')->nullable();

            // Provenance, per CLAUDE.md. Nullable because the fake driver has
            // no model and costs nothing, and recording "fake" as a model
            // name would pollute real usage accounting.
            $table->string('driver')->default('fake');
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();

            // Why a run failed, shown to the user rather than swallowed.
            $table->text('error')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['manuscript_section_id', 'created_at']);
            $table->index(['status']);
        });

        DB::statement(
            "ALTER TABLE editorial_reviews ADD CONSTRAINT editorial_reviews_status_check
             CHECK (status IN ('queued','running','completed','failed'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_reviews');
    }
};
