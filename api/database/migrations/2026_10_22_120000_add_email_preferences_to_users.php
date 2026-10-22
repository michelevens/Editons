<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Email preferences.
 *
 * One JSON column of overrides rather than a boolean per category: a missing
 * key means "the default" (on), so adding a category later is a constant in
 * App\Mail\EmailPreferences, not another migration. Mandatory categories
 * (security, billing, receipts) are never stored here at all.
 *
 * credits_low_notified_at keeps the low-credit warning to once a month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('email_preferences')->nullable();
            $table->timestamp('credits_low_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email_preferences', 'credits_low_notified_at']);
        });
    }
};
