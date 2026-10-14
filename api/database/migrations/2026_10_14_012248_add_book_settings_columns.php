<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the Book Settings screen stores.
 *
 * The three writing toggles live in one jsonb column rather than three boolean
 * ones. They are per-book preferences that only the client reads, the set will
 * grow (the prototype already hints at more), and a migration per checkbox is
 * the wrong trade. Anything another feature needs to query would earn its own
 * column instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('language')->default('English (US)')->after('genre');

            // autosave | inline_ai | review_on_save
            $table->jsonb('settings')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['language', 'settings']);
        });
    }
};
