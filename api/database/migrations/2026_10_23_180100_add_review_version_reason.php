<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A version reason for tracked changes decided in the editor.
 *
 * Accepting or rejecting a suggestion rewrites the chapter on the author's
 * say-so, not by typing; `review` keeps that distinguishable in the history,
 * as `ai_suggestion` does for an AI edit, with a label saying what was
 * accepted and rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE manuscript_versions DROP CONSTRAINT IF EXISTS manuscript_versions_reason_check');

        DB::statement(
            "ALTER TABLE manuscript_versions ADD CONSTRAINT manuscript_versions_reason_check
             CHECK (reason IN ('autosave','manual','import','restore','ai_suggestion','ai_draft','review'))"
        );
    }

    public function down(): void
    {
        DB::table('manuscript_versions')->where('reason', 'review')->update(['reason' => 'manual']);

        DB::statement('ALTER TABLE manuscript_versions DROP CONSTRAINT IF EXISTS manuscript_versions_reason_check');

        DB::statement(
            "ALTER TABLE manuscript_versions ADD CONSTRAINT manuscript_versions_reason_check
             CHECK (reason IN ('autosave','manual','import','restore','ai_suggestion','ai_draft'))"
        );
    }
};
