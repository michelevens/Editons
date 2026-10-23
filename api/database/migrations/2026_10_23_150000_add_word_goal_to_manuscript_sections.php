<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A word goal per chapter: the ring in the chapter header and beside the
 * chapter in the binder. Null means no goal, which is the default — most
 * chapters will never have one, and a ring at 0% would be a nag.
 *
 * The daily goal needs no column: users.daily_word_goal has existed since
 * the profile screen, and the weekly digest already reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manuscript_sections', function (Blueprint $table) {
            $table->unsignedInteger('word_goal')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('manuscript_sections', function (Blueprint $table) {
            $table->dropColumn('word_goal');
        });
    }
};
