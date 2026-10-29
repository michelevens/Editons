<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales reports and royalty statements (ADR-0046 — Sales reports and
 * royalty statements).
 *
 * - users.timezone: the IANA zone a person's reports cut days in. Null
 *   means the platform's reporting zone (config commerce.report_timezone,
 *   America/New_York) — the zone the platform's Haitian and North American
 *   authors share.
 * - An index on orders.paid_at for the date-range queries (the existing
 *   (status, paid_at) index cannot serve "paid in a range, any status").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone', 64)->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['paid_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
