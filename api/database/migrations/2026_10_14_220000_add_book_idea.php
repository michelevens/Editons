<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the book is about, before it is written.
 *
 * The Discover stage asks four questions, and their answers are what every
 * later stage reasons from: an outline is generated from them, the marketing
 * kit describes the book with them, and the editorial agents eventually need
 * to know who the reader is to judge whether the prose reaches them.
 *
 * Deliberately not the store `description` in `meta`. That is copy written to
 * sell a finished book; this is the author working out what they are making,
 * and the two are different documents with different audiences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // The one-sentence answer to "what is this book?"
            $table->text('premise')->nullable()->after('journey_tasks');

            // Who it is for, in the author's own words.
            $table->text('audience')->nullable()->after('premise');

            // What the reader should be able to do, or feel, afterwards.
            $table->text('promise')->nullable()->after('audience');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['premise', 'audience', 'promise']);
        });
    }
};
