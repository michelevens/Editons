<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cover templates (ADR — Cover backgrounds and series templates).
 *
 * - cover_templates: a named cover design (every panel's layers, the
 *   backgrounds, the spine's direction and colour, the faces; the pictures
 *   and artwork only when saved "with images", copied under
 *   cover-templates/{id}) owned by an account — an author's, or a house
 *   account run by staff. `version` counts its updates.
 * - books.cover_template_id: the template a book follows, or null. As with
 *   house styles, following is explicit, updating the template rewrites the
 *   cover design of the books that follow it, and deleting it leaves their
 *   covers as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cover_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->jsonb('design');
            $table->boolean('include_images')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('source_book_id')->nullable()->constrained('books')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        Schema::table('books', function (Blueprint $table) {
            $table->foreignId('cover_template_id')->nullable()->constrained('cover_templates')->nullOnDelete();
            $table->unsignedInteger('cover_template_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cover_template_id');
            $table->dropColumn('cover_template_version');
        });

        Schema::dropIfExists('cover_templates');
    }
};
