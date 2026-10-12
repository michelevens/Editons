<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A claim in the manuscript, tied to the source that supports it.
 *
 * This is the provenance record. A citation is deliberately NOT just a
 * pointer from text to a source: it stores the claim being made, so the
 * question "what exactly is this source being used to support?" has an
 * answer that survives the author rewriting the sentence.
 *
 * The locator (page, chapter, timestamp) is separate from the source
 * because "Smith 2019" and "Smith 2019, p.214" are different evidentiary
 * claims, and only the second one can be checked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('manuscript_section_id')->constrained()->cascadeOnDelete();

            // Nullable on purpose. An author marking "this needs a source"
            // before they have found one is the normal order of work, and
            // forcing a source here would mean the only way to record an
            // unsupported claim is to not record it at all.
            $table->foreignId('research_source_id')->nullable()
                ->constrained('research_sources')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // The assertion this citation is backing, in the author's words.
            $table->text('claim');

            // Where in the source: "p. 214", "ch. 3", "00:41:20".
            $table->string('locator')->nullable();

            // The manuscript text this attaches to, copied at creation time
            // for the same reason editorial_findings stores an excerpt: the
            // document moves, and a citation pointing at deleted text must be
            // detectable rather than silently wrong.
            $table->text('excerpt')->nullable();
            $table->unsignedInteger('from_offset')->nullable();
            $table->unsignedInteger('to_offset')->nullable();

            // needs_source | supported | contradicted
            //
            // "contradicted" exists because an honest research tool has to
            // let an author record that they checked and the source does not
            // say what they thought it said.
            $table->string('status')->default('needs_source');

            $table->timestamps();

            $table->index(['manuscript_section_id', 'status']);
            $table->index(['research_source_id']);
        });

        DB::statement(
            "ALTER TABLE citations ADD CONSTRAINT citations_status_check
             CHECK (status IN ('needs_source','supported','contradicted'))"
        );

        // A citation claiming support must name the source doing the
        // supporting. Enforced in the database because this is the integrity
        // rule the whole provenance feature rests on: without it, "supported"
        // could mean nothing at all.
        DB::statement(
            "ALTER TABLE citations ADD CONSTRAINT citations_supported_needs_source_check
             CHECK (status = 'needs_source' OR research_source_id IS NOT NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('citations');
    }
};
