<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The interface language (ADR — Interface languages: English, French,
 * Haitian Creole).
 *
 * Null until the account's first visit, when the browser's Accept-Language
 * picks one of App\I18n\Locales::SUPPORTED and it is stored; from then on
 * only the switcher changes it. Emails to the account use it too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
