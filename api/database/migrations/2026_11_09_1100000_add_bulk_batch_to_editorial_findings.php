<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bulk accept a finding was decided in (ADR — Safe apply, honest
 * prices, citation fixes).
 *
 * A bulk accept saves one version of each chapter it changes before it
 * starts, labelled with this batch, and "Undo this bulk accept" restores
 * those versions and reopens the findings carrying it. Null for every
 * finding decided one by one, and for every finding before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('editorial_findings', function (Blueprint $table) {
            $table->string('bulk_batch', 36)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('editorial_findings', function (Blueprint $table) {
            $table->dropColumn('bulk_batch');
        });
    }
};
