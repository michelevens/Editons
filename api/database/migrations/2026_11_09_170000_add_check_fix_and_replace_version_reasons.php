<?php

use App\Editorial\DriverFactory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two version reasons (ADR — Author walkthrough fixes):
 *
 * - `check_fix`: a deterministic check's fix applied on the author's
 *   approval (a house-style rule, the sample driver's heuristics). It was
 *   saved as `ai_suggestion`, so the history said "AI suggestion applied"
 *   and the AI disclosure counted it as AI.
 * - `replace`: a manuscript-wide find and replace, snapshotted first.
 *
 * Versions already saved as `ai_suggestion` for a fix no model wrote are
 * relabelled, matched on the label the applier wrote ("Applied: <finding
 * title>") and the finding's recorded driver. When a chapter has findings
 * of that title from a model too, the version stays AI: a disclosure must
 * not understate.
 */
return new class extends Migration
{
    private const BEFORE = "'autosave','manual','import','restore','ai_suggestion','ai_draft','review','typography'";

    public function up(): void
    {
        DB::statement('ALTER TABLE manuscript_versions DROP CONSTRAINT IF EXISTS manuscript_versions_reason_check');
        DB::statement('ALTER TABLE manuscript_versions ADD CONSTRAINT manuscript_versions_reason_check
             CHECK (reason IN ('.self::BEFORE.",'check_fix','replace'))");

        DB::table('manuscript_versions')
            ->where('reason', 'ai_suggestion')
            ->where('label', 'like', 'Applied: %')
            ->orderBy('id')
            ->select(['id', 'manuscript_section_id', 'label'])
            ->chunkById(500, function ($versions) {
                foreach ($versions as $version) {
                    $title = mb_substr($version->label, mb_strlen('Applied: '));
                    $findings = DB::table('editorial_findings')
                        ->leftJoin('editorial_reviews', 'editorial_reviews.id', '=', 'editorial_findings.editorial_review_id')
                        ->where('editorial_findings.manuscript_section_id', $version->manuscript_section_id)
                        ->where('editorial_findings.title', $title)
                        ->get(['editorial_findings.source', 'editorial_reviews.driver']);

                    $fromModel = $findings->contains(fn ($f) => $f->source !== 'style_check'
                        && ($f->driver === null || ! DriverFactory::simulated($f->driver)));

                    if ($findings->isNotEmpty() && ! $fromModel) {
                        DB::table('manuscript_versions')->where('id', $version->id)->update(['reason' => 'check_fix']);
                    }
                }
            });
    }

    public function down(): void
    {
        DB::table('manuscript_versions')->where('reason', 'check_fix')->update(['reason' => 'ai_suggestion']);
        DB::table('manuscript_versions')->where('reason', 'replace')->update(['reason' => 'manual']);

        DB::statement('ALTER TABLE manuscript_versions DROP CONSTRAINT IF EXISTS manuscript_versions_reason_check');
        DB::statement('ALTER TABLE manuscript_versions ADD CONSTRAINT manuscript_versions_reason_check
             CHECK (reason IN ('.self::BEFORE.'))');
    }
};
