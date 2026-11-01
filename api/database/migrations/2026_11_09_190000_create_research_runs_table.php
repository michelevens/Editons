<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI research runs and the provenance of what they found (ADR — AI
 * research, source-backed fact-checking, cover templates).
 *
 * A run keeps the question, the model and the sources the model actually
 * retrieved, so a source the author saves later can say where it came
 * from: retrieved by AI, on this date, by this model, for this query. The
 * results are a proposal; nothing reaches the library until the author
 * picks it.
 *
 * `provenance` on a research source holds that record. `evidence` on a
 * finding holds the fact-checker's verdict, the source and the excerpt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manuscript_section_id')->nullable()->constrained()->nullOnDelete();
            // topic | chapter | plan | claim
            $table->string('mode', 20);
            $table->text('query');
            $table->string('driver', 40);
            $table->string('model', 120)->nullable();
            $table->boolean('simulated')->default(false);
            // completed | failed
            $table->string('status', 20);
            $table->jsonb('results')->default('[]');
            $table->text('summary')->nullable();
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('searches')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->integer('credits')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['book_id', 'created_at']);
        });

        Schema::table('research_sources', function (Blueprint $table) {
            $table->jsonb('provenance')->nullable();
        });

        Schema::table('editorial_findings', function (Blueprint $table) {
            $table->jsonb('evidence')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('editorial_findings', fn (Blueprint $table) => $table->dropColumn('evidence'));
        Schema::table('research_sources', fn (Blueprint $table) => $table->dropColumn('provenance'));
        Schema::dropIfExists('research_runs');
    }
};
