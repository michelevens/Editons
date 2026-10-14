<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the Design, Cover Studio and Publishing screens store on a book.
 *
 * Three jsonb columns rather than thirty scalar ones. Layout, cover design and
 * store metadata are each a bag of presentation settings read as a whole by
 * exactly one screen and never queried field by field — trim size and drop cap
 * will never appear in a WHERE clause. Price and ISBN are the exception and get
 * real columns, because a store submission validates them and sales estimates
 * multiply by price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // trim, margins, font, size, leading, dropcap, headers
            $table->jsonb('layout')->nullable()->after('settings');

            // title/subtitle/author as they appear on the cover, typeface,
            // palette index, uploaded artwork path
            $table->jsonb('cover')->nullable()->after('layout');

            // description, categories, keywords — the store listing copy
            $table->jsonb('meta')->nullable()->after('cover');

            // Queried, so not in jsonb: a submission check reads them and the
            // sales screen multiplies by price.
            $table->decimal('list_price', 8, 2)->nullable()->after('meta');
            $table->string('isbn')->nullable()->after('list_price');
        });

        // One row per store a book is offered in. A join table rather than a
        // jsonb map: each row carries its own state machine and timestamps,
        // and "which books are live on Kindle" is a question worth asking.
        Schema::create('book_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();

            // amazon_kdp | apple_books | kobo | google_play | ingram_print
            $table->string('channel');
            $table->string('format');
            $table->string('status')->default('not_submitted');

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('went_live_at')->nullable();

            $table->timestamps();

            // A book is offered in a given store once. Without this, a double
            // submit creates two rows and the screen shows the store twice.
            $table->unique(['book_id', 'channel']);
        });

        DB::statement(
            "ALTER TABLE book_channels ADD CONSTRAINT book_channels_status_check
             CHECK (status IN ('not_submitted','in_review','live','unpublished'))"
        );

        DB::statement(
            "ALTER TABLE book_channels ADD CONSTRAINT book_channels_format_check
             CHECK (format IN ('ebook','print','audio'))"
        );

        // A live channel must know when it went live, and one that was never
        // submitted must not claim a submission date. The screen reports both
        // dates, so a row that contradicts itself would be shown to an author.
        DB::statement(
            "ALTER TABLE book_channels ADD CONSTRAINT book_channels_dates_check
             CHECK (
               (status = 'not_submitted' AND submitted_at IS NULL)
               OR (status <> 'not_submitted' AND submitted_at IS NOT NULL)
             )"
        );

        // Generated files. Append-only: regenerating adds a row rather than
        // overwriting, which is what makes the export history on the
        // Publishing screen real rather than decorative.
        Schema::create('book_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();

            // epub | pdf_print | docx | md | html
            $table->string('format');
            $table->string('status')->default('generating');

            // Counted at generation time, not now: the point of history is
            // what the book looked like when the file was made.
            $table->unsignedInteger('word_count')->default(0);
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->string('path')->nullable();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['book_id', 'created_at']);
        });

        DB::statement(
            "ALTER TABLE book_exports ADD CONSTRAINT book_exports_status_check
             CHECK (status IN ('generating','ready','failed'))"
        );

        // A finished file must say where it is. A ready export with no path is
        // a download button that 404s.
        DB::statement(
            "ALTER TABLE book_exports ADD CONSTRAINT book_exports_path_check
             CHECK (status <> 'ready' OR path IS NOT NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('book_exports');
        Schema::dropIfExists('book_channels');

        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['layout', 'cover', 'meta', 'list_price', 'isbn']);
        });
    }
};
