<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fields a citation style actually needs.
 *
 * Author, year and title are enough for a reading list; a thesis cites
 * journal articles, and every style puts the journal, volume, issue and pages
 * somewhere different. Without them each style could only rearrange the same
 * three facts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_sources', function (Blueprint $table) {
            $table->string('container')->nullable()->after('publisher');
            $table->string('volume', 40)->nullable()->after('container');
            $table->string('issue', 40)->nullable()->after('volume');
            $table->string('pages', 40)->nullable()->after('issue');
            $table->string('edition', 40)->nullable()->after('pages');
        });
    }

    public function down(): void
    {
        Schema::table('research_sources', function (Blueprint $table) {
            $table->dropColumn(['container', 'volume', 'issue', 'pages', 'edition']);
        });
    }
};
