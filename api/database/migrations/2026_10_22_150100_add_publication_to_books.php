<?php

use App\Models\Isbn;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the generated pages print: publisher, imprint, edition statement,
 * copyright holder and year, ISBNs per format, legal deposit, printing line,
 * credits, disclaimer, place of publication.
 *
 * One jsonb column for the copyright-page bag, read whole by one service
 * (App\Publishing\Anatomy). The series gets real columns, because "the other
 * books in this series by this owner" is a query, and the series page is
 * built from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('series_name')->nullable()->after('subtitle');
            $table->unsignedSmallInteger('series_number')->nullable()->after('series_name');
            $table->jsonb('publication')->nullable()->after('rights');

            $table->index(['user_id', 'series_name']);
        });

        // The single ISBN the publishing screen has always stored is the
        // print one when it is valid. An invalid one is left where it is
        // rather than copied into a field the copyright page prints.
        DB::table('books')->whereNotNull('isbn')->select(['id', 'isbn'])->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    if (Isbn::isValid((string) $row->isbn)) {
                        DB::table('books')->where('id', $row->id)->update([
                            'publication' => json_encode(['isbn_print' => trim((string) $row->isbn)]),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'series_name']);
            $table->dropColumn(['series_name', 'series_number', 'publication']);
        });
    }
};
