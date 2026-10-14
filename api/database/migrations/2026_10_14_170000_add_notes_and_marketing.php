<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The author's own notes, and the generated marketing kit.
 *
 * Notes are free text on the book: a scratchpad for the decisions that govern
 * the writing — who the reader is, what the tone should be — which belong
 * beside the manuscript rather than in a separate document nobody opens.
 *
 * The marketing kit is one jsonb blob because it is generated as a whole and
 * replaced as a whole. Splitting it into columns would mean a migration every
 * time the generator learns to produce another piece of copy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('launch_tasks');

            // audience, description, email, posts, generated_at
            $table->jsonb('marketing_kit')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['notes', 'marketing_kit']);
        });
    }
};
