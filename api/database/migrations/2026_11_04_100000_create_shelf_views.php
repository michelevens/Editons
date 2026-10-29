<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Shelf views and bulk actions (ADR-0045 — Shelf views and bulk actions).
 *
 * - shelf_views: how a person last looked at a shelf (name null: restored
 *   on return) and the few views they named. Per user and per shelf: the
 *   author's own (books) and the console's House catalog (house).
 * - books.search_text: title, subtitle, series and author, lower-case and
 *   with accents folded (App\Shelf\Shelf::fold), so "reussir" finds
 *   "Réussir" without the unaccent extension. Kept by the Book model.
 * - Indexes the shelf's filters and its per-page column queries use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shelf_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 20);
            $table->string('name', 60)->nullable();
            $table->jsonb('state');
            $table->timestamps();
            $table->index(['user_id', 'scope']);
        });

        // One "current" view per shelf, and named views unique per shelf
        // whatever their case.
        DB::statement('CREATE UNIQUE INDEX shelf_views_current_unique ON shelf_views (user_id, scope) WHERE name IS NULL');
        DB::statement('CREATE UNIQUE INDEX shelf_views_name_unique ON shelf_views (user_id, scope, lower(name)) WHERE name IS NOT NULL');

        Schema::table('books', function (Blueprint $table) {
            $table->text('search_text')->nullable();
            $table->index(['user_id', 'status']);
            $table->index(['series_name', 'series_number']);
        });

        Schema::table('book_exports', function (Blueprint $table) {
            $table->index(['book_id', 'format', 'status']);
        });

        Schema::table('isbns', function (Blueprint $table) {
            $table->index('book_id');
        });

        DB::table('books')->orderBy('id')->select('id', 'title', 'subtitle', 'series_name', 'author_name')
            ->chunkById(500, function ($books) {
                foreach ($books as $book) {
                    $text = implode(' ', array_filter([$book->title, $book->subtitle, $book->series_name, $book->author_name]));
                    DB::table('books')->where('id', $book->id)->update(['search_text' => Str::lower(Str::ascii($text))]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('isbns', function (Blueprint $table) {
            $table->dropIndex(['book_id']);
        });

        Schema::table('book_exports', function (Blueprint $table) {
            $table->dropIndex(['book_id', 'format', 'status']);
        });

        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex(['series_name', 'series_number']);
            $table->dropIndex(['user_id', 'status']);
            $table->dropColumn('search_text');
        });

        Schema::dropIfExists('shelf_views');
    }
};
