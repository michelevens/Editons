<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A version reason for the "Fix typography" pass (ADR — Book language and
 * typography).
 *
 * The pass rewrites quotes, spacing, dashes and ellipses across the book on
 * the author's say-so; each chapter it changes is snapshotted first with
 * reason `typography`, so the history says what happened and the author can
 * go back to the text as typed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE manuscript_versions DROP CONSTRAINT IF EXISTS manuscript_versions_reason_check');

        DB::statement(
            "ALTER TABLE manuscript_versions ADD CONSTRAINT manuscript_versions_reason_check
             CHECK (reason IN ('autosave','manual','import','restore','ai_suggestion','ai_draft','review','typography'))"
        );
    }

    public function down(): void
    {
        DB::table('manuscript_versions')->where('reason', 'typography')->update(['reason' => 'manual']);

        DB::statement('ALTER TABLE manuscript_versions DROP CONSTRAINT IF EXISTS manuscript_versions_reason_check');

        DB::statement(
            "ALTER TABLE manuscript_versions ADD CONSTRAINT manuscript_versions_reason_check
             CHECK (reason IN ('autosave','manual','import','restore','ai_suggestion','ai_draft','review'))"
        );
    }
};
