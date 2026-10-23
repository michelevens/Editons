<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Light or dark, chosen per account.
 *
 * 'system' (the default) follows the device's prefers-color-scheme; 'light'
 * and 'dark' override it. Stored on the account rather than in the browser
 * so an author who picks dark gets dark on every device they sign in from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('theme', 10)->default('system');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
