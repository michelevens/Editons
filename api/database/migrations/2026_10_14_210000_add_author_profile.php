<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who the author is, as distinct from who the account is.
 *
 * `name` is the person using the application. `author_name` is the name that
 * goes on a cover, into EPUB metadata and onto a store listing, and the two
 * are routinely different — a pen name, a name with credentials after it, a
 * married name the books were not published under. Conflating them means an
 * author cannot change one without changing the other.
 *
 * The bio flows into the Author Website and the About the Author page, which
 * is why it belongs here rather than per book: it is the same text every
 * time, and retyping it per book is how it ends up inconsistent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('author_name')->nullable()->after('name');
            $table->text('bio')->nullable()->after('author_name');
            $table->string('website')->nullable()->after('bio');

            // Defaults for a new book, so starting the next one is faster
            // than starting the first. Nullable: an author who writes in one
            // genre benefits, and one who does not is not forced to choose.
            $table->string('default_book_type')->nullable()->after('website');
            $table->unsignedInteger('daily_word_goal')->nullable()->after('default_book_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'author_name', 'bio', 'website', 'default_book_type', 'daily_word_goal',
            ]);
        });
    }
};
