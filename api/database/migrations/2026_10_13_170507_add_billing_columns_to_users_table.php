<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plan state on the account.
 *
 * Cashier's own tables carry the Stripe subscription; this carries the plan
 * the application enforces limits against. They are kept separate because the
 * plan must be readable without a Stripe round-trip on every page, and because
 * a free account has a plan but no subscription at all.
 *
 * Note what is NOT here: a credit balance. That lives in the ledger, where it
 * can be explained — see create_credit_transactions_table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // free | author | studio
            $table->string('plan')->default('free')->after('password');

            // When the current period's credits were last granted. The monthly
            // top-up is driven by Stripe's invoice.paid webhook, and this is
            // what makes a replayed webhook idempotent.
            $table->timestamp('credits_granted_at')->nullable()->after('plan');

            // Cashier adds stripe_id/pm_type/pm_last_four/trial_ends_at in its
            // own migration; nothing here duplicates them.
        });

        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_plan_check
             CHECK (plan IN ('free','author','studio'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_plan_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['plan', 'credits_granted_at']);
        });
    }
};
