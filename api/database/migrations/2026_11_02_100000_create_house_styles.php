<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * House styles (ADR — Mirrored margins, trims, typography and house styles).
 *
 * - house_styles: a named book design (the whole `layout`: trim, margins,
 *   type, openers, heads, contents, boxes, paragraphs, headings) owned by
 *   an account — an author's, or a house account run by staff. `version`
 *   counts its updates.
 * - books.house_style_id: the style a book follows, or null. Following is
 *   explicit: a book follows only when someone applied a style with
 *   "follow", and updating the style rewrites the design of the books that
 *   follow it. Deleting a style leaves its books' designs as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('house_styles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->jsonb('design');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::table('books', function (Blueprint $table) {
            $table->foreignId('house_style_id')->nullable()->constrained('house_styles')->nullOnDelete();
            $table->unsignedInteger('house_style_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropConstrainedForeignId('house_style_id');
            $table->dropColumn('house_style_version');
        });

        Schema::dropIfExists('house_styles');
    }
};
