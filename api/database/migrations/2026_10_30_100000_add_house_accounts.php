<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * House publisher accounts (ADR — house catalog and house publisher accounts).
 *
 *  - users.account_kind: 'author' (everybody, as before) or 'house', a
 *    platform-owned publisher account that nobody signs in to. Its books
 *    are run by staff from the console's House catalog, and its sales are
 *    the platform's revenue.
 *  - users.house_slug: a house account's stable name for the shell
 *    (`house:transfer … --to=ecrivain-prepa`). Null for authors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('account_kind', 16)->default('author');
            $table->string('house_slug', 40)->nullable()->unique();

            $table->index('account_kind');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['account_kind']);
            $table->dropUnique(['house_slug']);
            $table->dropColumn(['account_kind', 'house_slug']);
        });
    }
};
