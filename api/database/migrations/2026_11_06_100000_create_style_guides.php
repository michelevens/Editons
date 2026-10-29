<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Style guides and review options (ADR — Style guides and review options).
 *
 * - style_guides: a named editorial style (language variety, preferred and
 *   banned terms, dictionary, numbers, punctuation…) owned by an account —
 *   an author's, or a house account run by staff. `version` is the latest
 *   saved version's number.
 * - style_guide_versions: every save, immutable (a trigger refuses UPDATE).
 *   A review records the version it used, so "what did the editor know"
 *   has an answer after the guide changes.
 * - books.style_guide_id: the guide a book follows, or null. Following is
 *   explicit, as with house styles (ADR-0041); the book follows the guide's
 *   latest version. books.style_overrides: the book's own additions — words
 *   never flagged, rules turned off, and its own values for any rule.
 * - editorial_reviews: which guide version and options a run used.
 * - editorial_findings: rule, term and source, for triage and "never flag
 *   this again"; dismissal says why a finding was dismissed in bulk, so the
 *   undo reopens exactly those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('style_guides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::create('style_guide_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('style_guide_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->jsonb('rules');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['style_guide_id', 'version']);
        });

        // Saved versions never change. The model refuses too; this holds
        // for anything that writes past it.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION style_guide_versions_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'style guide versions are immutable';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER style_guide_versions_no_update
                BEFORE UPDATE ON style_guide_versions
                FOR EACH ROW EXECUTE FUNCTION style_guide_versions_immutable();
            SQL);

        Schema::table('books', function (Blueprint $table) {
            $table->foreignId('style_guide_id')->nullable()->constrained('style_guides')->nullOnDelete();
            $table->jsonb('style_overrides')->nullable();
        });

        Schema::table('editorial_reviews', function (Blueprint $table) {
            $table->foreignId('style_guide_version_id')->nullable()->constrained('style_guide_versions')->nullOnDelete();
            // sha1 of the rules the agent was given (guide + the book's own),
            // so two runs on the same rules are recognisably the same.
            $table->string('style_digest', 40)->nullable();
            // {scope, sensitivity, focus, run_id}
            $table->jsonb('options')->nullable();
        });

        Schema::table('editorial_findings', function (Blueprint $table) {
            $table->string('rule', 120)->nullable();
            $table->string('term', 120)->nullable();
            $table->string('source', 20)->default('agent'); // agent | style_check
            $table->string('dismissal', 20)->nullable();    // dictionary | rule_off | bulk

            $table->index(['manuscript_section_id', 'rule']);
        });
    }

    public function down(): void
    {
        Schema::table('editorial_findings', function (Blueprint $table) {
            $table->dropIndex(['manuscript_section_id', 'rule']);
            $table->dropColumn(['rule', 'term', 'source', 'dismissal']);
        });

        Schema::table('editorial_reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('style_guide_version_id');
            $table->dropColumn(['style_digest', 'options']);
        });

        Schema::table('books', function (Blueprint $table) {
            $table->dropConstrainedForeignId('style_guide_id');
            $table->dropColumn('style_overrides');
        });

        Schema::dropIfExists('style_guide_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS style_guide_versions_immutable()');
        Schema::dropIfExists('style_guides');
    }
};
