<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make AI-written text distinguishable in version history.
 *
 * Accepting an editorial suggestion was recorded as `manual`, which made an
 * AI-authored sentence indistinguishable from one the author typed. The
 * credit ledger knew a review had run; nothing knew whether its output was
 * accepted into the manuscript.
 *
 * That distinction cannot be reconstructed afterwards. Once a version is
 * stored as `manual` the information is gone — there is no record anywhere
 * of which words came from where, and no amount of later work recovers it.
 *
 * It matters now because an author may be asked to declare AI assistance:
 * universities increasingly require it of theses, and some publishers ask.
 * A declaration can only be honest if the history is.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE manuscript_versions DROP CONSTRAINT IF EXISTS manuscript_versions_reason_check');

        DB::statement(
            "ALTER TABLE manuscript_versions ADD CONSTRAINT manuscript_versions_reason_check
             CHECK (reason IN ('autosave','manual','import','restore','ai_suggestion','ai_draft'))"
        );
    }

    public function down(): void
    {
        // Rows written with the new reasons would violate the old constraint,
        // so they are folded back into `manual` — which is exactly the loss
        // of information this migration exists to prevent. Down is here for
        // completeness, not because running it is a good idea.
        DB::table('manuscript_versions')
            ->whereIn('reason', ['ai_suggestion', 'ai_draft'])
            ->update(['reason' => 'manual']);

        DB::statement('ALTER TABLE manuscript_versions DROP CONSTRAINT IF EXISTS manuscript_versions_reason_check');

        DB::statement(
            "ALTER TABLE manuscript_versions ADD CONSTRAINT manuscript_versions_reason_check
             CHECK (reason IN ('autosave','manual','import','restore'))"
        );
    }
};
