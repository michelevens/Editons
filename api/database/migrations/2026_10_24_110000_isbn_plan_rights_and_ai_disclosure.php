<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an author declares before a book goes to the stores.
 *
 * - isbn_plan: for each edition (ebook, paperback, hardcover), whether it
 *   carries the author's own ISBN or one a store assigns, which ties the
 *   edition to that store (App\Publishing\IsbnPlan).
 * - rights_declaration: that the author holds the rights, the third-party
 *   material in the book and the permission held for each, with who
 *   confirmed it and when (App\Publishing\RightsDeclaration).
 * - ai_disclosure: the author's answers to KDP's AI-content questions,
 *   with the provenance evidence they were shown, who confirmed them and
 *   when (App\Publishing\AiDisclosure).
 *
 * Statements of fact by a person, so none is mass assignable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->jsonb('isbn_plan')->nullable();
            $table->jsonb('rights_declaration')->nullable();
            $table->jsonb('ai_disclosure')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('books', fn (Blueprint $table) => $table->dropColumn(['isbn_plan', 'rights_declaration', 'ai_disclosure']));
    }
};
