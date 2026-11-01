<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a web source was consulted (ADR — Safe apply, honest prices,
 * citation fixes): Chicago, MLA, APA and Harvard print it for a web page
 * ("Accessed October 31, 2026."). As the author or the file gave it
 * (BibTeX `urldate`, RIS `Y2`): a date, never a timestamp of import.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_sources', function (Blueprint $table) {
            $table->string('accessed', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('research_sources', function (Blueprint $table) {
            $table->dropColumn('accessed');
        });
    }
};
