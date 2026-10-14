<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ISBNs, the rights checklist, and the link between a book and its editions.
 *
 * An ISBN belongs to the author's account, not to a book: you buy a block,
 * then assign numbers to editions as you publish them, and unassigning one
 * returns it to the pool. Hanging it off a book would make the pool
 * unrepresentable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('isbns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('isbn');

            // Null means unassigned — sitting in the pool. Nullable rather
            // than a separate pool table, because assignment is the only
            // difference between the two states.
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();

            // ebook | paperback | hardcover | large_print | audiobook
            $table->string('edition_format')->nullable();

            // Whether the author bought it or a store issued a free one. A
            // store-issued ISBN lists the store as publisher, which is a real
            // consequence the screen has to be able to warn about.
            $table->string('source')->default('purchased');

            $table->timestamps();

            // An ISBN is globally unique by definition. Scoped to the user
            // because two accounts entering their own numbers must not
            // collide with each other's typos.
            $table->unique(['user_id', 'isbn']);
        });

        DB::statement(
            "ALTER TABLE isbns ADD CONSTRAINT isbns_source_check
             CHECK (source IN ('purchased','store_issued','own'))"
        );

        // Each format of a book needs its own ISBN, and one number cannot
        // cover two formats. Enforced here because assigning the same number
        // twice is a real publishing error, not just a UI slip.
        DB::statement(
            "CREATE UNIQUE INDEX isbns_one_per_edition
             ON isbns (book_id, edition_format)
             WHERE book_id IS NOT NULL"
        );

        // An assigned ISBN must name the format it covers, and an unassigned
        // one must not pretend to.
        DB::statement(
            "ALTER TABLE isbns ADD CONSTRAINT isbns_assignment_check
             CHECK (
               (book_id IS NULL AND edition_format IS NULL)
               OR (book_id IS NOT NULL AND edition_format IS NOT NULL)
             )"
        );

        Schema::table('books', function (Blueprint $table) {
            // An edition points at the book it was derived from. Null for an
            // original. nullOnDelete, not cascade: deleting a translation's
            // source should not delete the translation.
            $table->foreignId('parent_book_id')->nullable()->after('user_id')
                ->constrained('books')->nullOnDelete();

            // workbook | translation | large_print — what kind of edition this
            // is, null for an original.
            $table->string('edition_type')->nullable()->after('parent_book_id');

            // registered_copyright, permissions_cleared, image_licenses:
            // manual attestations the author ticks. Not derivable from the
            // manuscript, unlike the copyright-page check which is.
            $table->jsonb('rights')->nullable()->after('isbn');
        });

        // A book cannot be its own parent. Postgres will not catch a longer
        // cycle, but the one-step case is the one a buggy clone would create.
        DB::statement(
            'ALTER TABLE books ADD CONSTRAINT books_parent_not_self_check
             CHECK (parent_book_id IS NULL OR parent_book_id <> id)'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE books DROP CONSTRAINT IF EXISTS books_parent_not_self_check');

        Schema::table('books', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_book_id');
            $table->dropColumn(['edition_type', 'rights']);
        });

        Schema::dropIfExists('isbns');
    }
};
