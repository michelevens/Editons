<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Foreign keys for Cashier's subscriptions and subscription_items.
 *
 * Cashier's stock migration declares subscriptions.user_id and
 * subscription_items.subscription_id without constraints, so deleting an
 * account left its subscription rows pointing at nobody.
 *
 * Orphans are removed first, or the constraint cannot be added. They are
 * logged with their Stripe ids before they go: a row whose user no longer
 * exists cannot be shown to or billed through anybody here, but the Stripe
 * id is what support needs to find the subscription on Stripe's side. None
 * existed in development or production when this was written.
 *
 * Cascade on delete: ProfileController::destroy cancels the account's live
 * subscriptions on Stripe before deleting the user, so what cascades is the
 * local mirror of subscriptions that have already ended.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('subscriptions')
            ->whereNotIn('user_id', DB::table('users')->select('id'))
            ->get(['id', 'user_id', 'stripe_id', 'stripe_status']);

        if ($orphans->isNotEmpty()) {
            Log::warning('Removing subscriptions whose user no longer exists', ['subscriptions' => $orphans->all()]);

            DB::table('subscription_items')->whereIn('subscription_id', $orphans->pluck('id'))->delete();
            DB::table('subscriptions')->whereIn('id', $orphans->pluck('id'))->delete();
        }

        $items = DB::table('subscription_items')
            ->whereNotIn('subscription_id', DB::table('subscriptions')->select('id'))
            ->get(['id', 'subscription_id', 'stripe_id']);

        if ($items->isNotEmpty()) {
            Log::warning('Removing subscription items whose subscription no longer exists', ['items' => $items->all()]);

            DB::table('subscription_items')->whereIn('id', $items->pluck('id'))->delete();
        }

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('subscription_items', function (Blueprint $table) {
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subscription_items', fn (Blueprint $table) => $table->dropForeign(['subscription_id']));
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropForeign(['user_id']));
    }
};
