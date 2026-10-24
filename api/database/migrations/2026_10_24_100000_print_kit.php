<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The print-on-demand kit.
 *
 * Exports gain `options` (a cover wrap is made for one service and paper)
 * and `pages` (the print interior's real page count, which sizes the
 * spine and drives the print checks). Books gain `print`: paper, ink and
 * finish, which the spine width and the print cost both depend on.
 *
 * The print interior and the cover wrap render on the worker like PDF and
 * EPUB (each typesets the whole book), so the one-render-per-format index
 * covers them too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('book_exports', function (Blueprint $table) {
            $table->jsonb('options')->nullable();
            $table->unsignedInteger('pages')->nullable();
        });

        Schema::table('books', function (Blueprint $table) {
            $table->jsonb('print')->nullable();
        });

        DB::statement('DROP INDEX IF EXISTS book_exports_one_render_per_format');
        DB::statement(
            "CREATE UNIQUE INDEX book_exports_one_render_per_format
             ON book_exports (book_id, format)
             WHERE status = 'generating' AND format IN ('pdf','epub','pdf_interior','cover_wrap')"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS book_exports_one_render_per_format');
        DB::statement("UPDATE book_exports SET status = 'failed' WHERE status = 'generating' AND format IN ('pdf_interior','cover_wrap')");
        DB::statement(
            "CREATE UNIQUE INDEX book_exports_one_render_per_format
             ON book_exports (book_id, format)
             WHERE status = 'generating' AND format IN ('pdf','epub')"
        );

        Schema::table('books', fn (Blueprint $table) => $table->dropColumn('print'));
        Schema::table('book_exports', fn (Blueprint $table) => $table->dropColumn(['options', 'pages']));
    }
};
