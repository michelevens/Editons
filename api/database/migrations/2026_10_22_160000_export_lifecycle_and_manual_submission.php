<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Export lifecycle and honest store submission.
 *
 * Exports gain 'expired': a row that was ready once but whose file is no
 * longer in storage (written to the container's disk before the bucket
 * existed). Listing it as ready offered a download that could only fail.
 *
 * At most one PDF or EPUB per book renders at a time. Each takes a worker
 * for up to half an hour, so a second click used to queue a second render
 * of the same book ahead of everybody else's. The controller returns the
 * running row; the partial unique index is what makes that hold when two
 * requests race.
 *
 * Store channels gain 'ready_to_submit'. No store integration exists, so
 * "Submit" cannot send anything: it now records that the book is ready and
 * the author uploads it. Like not_submitted it has no submission date,
 * because nothing has been submitted yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE book_exports DROP CONSTRAINT IF EXISTS book_exports_status_check');
        DB::statement(
            "ALTER TABLE book_exports ADD CONSTRAINT book_exports_status_check
             CHECK (status IN ('generating','ready','failed','expired'))"
        );

        // Duplicates already queued keep the newest; the rest could never
        // be told apart on screen anyway.
        DB::statement(
            "UPDATE book_exports SET status = 'failed', updated_at = now()
             WHERE status = 'generating' AND format IN ('pdf','epub')
               AND id NOT IN (
                 SELECT max(id) FROM book_exports
                 WHERE status = 'generating' AND format IN ('pdf','epub')
                 GROUP BY book_id, format
               )"
        );

        DB::statement(
            "CREATE UNIQUE INDEX book_exports_one_render_per_format
             ON book_exports (book_id, format)
             WHERE status = 'generating' AND format IN ('pdf','epub')"
        );

        DB::statement('ALTER TABLE book_channels DROP CONSTRAINT IF EXISTS book_channels_status_check');
        DB::statement(
            "ALTER TABLE book_channels ADD CONSTRAINT book_channels_status_check
             CHECK (status IN ('not_submitted','ready_to_submit','in_review','live','unpublished'))"
        );

        DB::statement('ALTER TABLE book_channels DROP CONSTRAINT IF EXISTS book_channels_dates_check');
        DB::statement(
            "ALTER TABLE book_channels ADD CONSTRAINT book_channels_dates_check
             CHECK (
               (status IN ('not_submitted','ready_to_submit') AND submitted_at IS NULL)
               OR (status NOT IN ('not_submitted','ready_to_submit') AND submitted_at IS NOT NULL)
             )"
        );
    }

    public function down(): void
    {
        DB::statement("UPDATE book_channels SET status = 'not_submitted' WHERE status = 'ready_to_submit'");

        DB::statement('ALTER TABLE book_channels DROP CONSTRAINT IF EXISTS book_channels_dates_check');
        DB::statement(
            "ALTER TABLE book_channels ADD CONSTRAINT book_channels_dates_check
             CHECK (
               (status = 'not_submitted' AND submitted_at IS NULL)
               OR (status <> 'not_submitted' AND submitted_at IS NOT NULL)
             )"
        );

        DB::statement('ALTER TABLE book_channels DROP CONSTRAINT IF EXISTS book_channels_status_check');
        DB::statement(
            "ALTER TABLE book_channels ADD CONSTRAINT book_channels_status_check
             CHECK (status IN ('not_submitted','in_review','live','unpublished'))"
        );

        DB::statement('DROP INDEX IF EXISTS book_exports_one_render_per_format');

        // An expired row was ready and has no file: failed is the nearest
        // old status that does not promise a download.
        DB::statement("UPDATE book_exports SET status = 'failed' WHERE status = 'expired'");
        DB::statement('ALTER TABLE book_exports DROP CONSTRAINT IF EXISTS book_exports_status_check');
        DB::statement(
            "ALTER TABLE book_exports ADD CONSTRAINT book_exports_status_check
             CHECK (status IN ('generating','ready','failed'))"
        );
    }
};
