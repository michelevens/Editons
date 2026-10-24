<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The admin console's business pages (ADR — console business: billing,
 * plans, book sales, growth, onboarding).
 *
 *  - plans and plan_prices: the plan catalogue, until now config/billing.php.
 *    Seeded here from that file, so on the day this ships every limit is
 *    what it was; the config stays the fallback (App\Billing\PlanCatalog).
 *    A price is never edited: a new price is a new row (and a new Stripe
 *    price), and the old row stays so subscribers on it are still valued;
 *  - subscription state Cashier does not keep (when the period ends, when a
 *    payment first failed) and why people cancelled, from Stripe's webhooks;
 *  - refunds started from the console, and royalty payouts recorded by hand;
 *  - where an account came from (first touch), saved audiences, broadcasts
 *    and who each went to, discount codes and their redemptions, and the
 *    lifecycle emails with a once-per-account record of each send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            // Stable: users.plan stores it. Never renamed.
            $table->string('key', 32)->unique();
            $table->string('name', 60);
            // What new subscribers pay, per month. History is plan_prices.
            $table->unsignedInteger('price_cents');
            $table->string('currency', 3)->default('usd');
            $table->unsignedInteger('credits');
            // Null: unlimited.
            $table->unsignedInteger('books')->nullable();
            $table->unsignedInteger('seats')->nullable();
            $table->json('formats');
            // Which editorial specialists run (App\Editorial\EditorialAgent::minimumTier).
            $table->unsignedSmallInteger('tier')->default(0);
            // public: offered; hidden: not offered, can be given; legacy:
            // kept for the people on it, offered to nobody.
            $table->string('visibility', 16)->default('public');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('stripe_price_id')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_visibility_check CHECK (visibility IN ('public','hidden','legacy'))");

        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            // Null when Stripe was not configured as the price was set.
            $table->string('stripe_price_id')->nullable()->index();
            $table->timestamp('active_from');
            $table->timestamp('retired_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        $this->seedPlans();

        // users.plan was held to the three configured keys by a CHECK. The
        // catalogue is now the list: a foreign key to it, so an account can
        // only be on a plan that exists, and a plan with accounts on it
        // cannot be deleted.
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_plan_check');
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('plan')->references('key')->on('plans')->cascadeOnUpdate()->restrictOnDelete();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            // Kept by App\Billing\SubscriptionSync from Stripe's events.
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('past_due_since')->nullable();
        });

        Schema::create('subscription_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stripe_subscription_id')->unique();
            $table->string('plan_key', 32)->nullable();
            // What the subscription was worth a month when it was cancelled.
            $table->unsignedInteger('mrr_cents')->nullable();
            // Stripe's cancellation_details: why it ended
            // (cancellation_requested | payment_failed | payment_disputed),
            // the customer's chosen feedback and their comment.
            $table->string('stripe_reason', 40)->nullable();
            $table->string('feedback', 40)->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('ends_at')->nullable();
            // Set when a cancellation scheduled for the period's end is undone.
            $table->timestamp('resumed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('credit_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->string('gateway', 16);
            $table->string('gateway_refund_id')->nullable();
            // succeeded | pending | failed, as the gateway answered.
            $table->string('status', 16);
            $table->text('reason');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency', 3);
            $table->unsignedInteger('amount_cents');
            // bank_transfer | paypal | wise | cheque | other: paid outside
            // Écrivain; Stripe Connect is not built.
            $table->string('method', 24);
            $table->string('reference')->nullable();
            $table->date('paid_on');
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'currency']);
        });

        Schema::table('users', function (Blueprint $table) {
            // First touch, from the attribution cookie, at registration.
            $table->string('signup_source', 100)->nullable()->index();
            $table->string('signup_medium', 100)->nullable();
            $table->string('signup_campaign', 150)->nullable();
            $table->string('signup_referrer', 255)->nullable();
            $table->string('signup_landing', 255)->nullable();
        });

        Schema::create('audiences', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->jsonb('rules');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 150);
            $table->string('preheader', 200)->nullable();
            $table->text('body');
            $table->string('cta_label', 60)->nullable();
            $table->string('cta_url', 500)->nullable();
            // Null: every account that allows the mail.
            $table->foreignId('audience_id')->nullable()->constrained()->nullOnDelete();
            // The audience as it was when the broadcast was sent.
            $table->string('audience_name', 120)->nullable();
            $table->jsonb('audience_rules')->nullable();
            $table->boolean('inbox')->default(false);
            // draft | scheduled | sending | sent | cancelled
            $table->string('status', 16)->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('recipients_total')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });

        DB::statement("ALTER TABLE broadcasts ADD CONSTRAINT broadcasts_status_check CHECK (status IN ('draft','scheduled','sending','sent','cancelled'))");

        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // queued | sent | failed | skipped
            $table->string('status', 16)->default('queued');
            $table->string('skip_reason', 40)->nullable();
            $table->text('error')->nullable();
            $table->boolean('inboxed')->default(false);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // One message per account per broadcast, whatever retries.
            $table->unique(['broadcast_id', 'user_id']);
            $table->index(['broadcast_id', 'status']);
        });

        Schema::create('discount_codes', function (Blueprint $table) {
            $table->id();
            // Stored upper case; matched case-insensitively.
            $table->string('code', 40)->unique();
            $table->string('description', 255)->nullable();
            // percent | amount
            $table->string('kind', 8);
            $table->unsignedSmallInteger('percent_off')->nullable();
            $table->unsignedInteger('amount_off_cents')->nullable();
            $table->string('currency', 3)->nullable();
            $table->boolean('applies_to_plans')->default(false);
            $table->boolean('applies_to_storefront')->default(false);
            // Plans it applies to; null: every paid plan.
            $table->json('plan_keys')->nullable();
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('first_time_only')->default(false);
            $table->boolean('active')->default(true);
            // For plan checkout, once that exists; null without Stripe.
            $table->string('stripe_coupon_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE discount_codes ADD CONSTRAINT discount_codes_kind_check CHECK ((kind = 'percent' AND percent_off BETWEEN 1 AND 100) OR (kind = 'amount' AND amount_off_cents > 0 AND currency IS NOT NULL))");

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('discount_code_id')->nullable()->constrained()->nullOnDelete();
            // What the code took off; total_cents is what the buyer paid.
            $table->unsignedInteger('discount_cents')->default(0);
        });

        Schema::create('discount_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discount_code_id')->constrained()->cascadeOnDelete();
            // One redemption per order: a repeated webhook redeems nothing.
            $table->foreignId('order_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            // storefront | plan
            $table->string('context', 16);
            $table->unsignedInteger('amount_off_cents');
            $table->string('currency', 3);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('lifecycle_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 120);
            // no_book | inactive (App\Growth\Lifecycle::TRIGGERS)
            $table->string('trigger', 24);
            $table->unsignedSmallInteger('delay_days');
            $table->string('subject', 150);
            $table->text('body');
            $table->string('cta_label', 60)->nullable();
            $table->boolean('enabled')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('lifecycle_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lifecycle_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // sent | skipped (preferences) | failed
            $table->string('status', 16);
            $table->timestamp('created_at')->useCurrent();

            // Once per account, ever.
            $table->unique(['lifecycle_campaign_id', 'user_id']);
        });

        // Off until the owner switches them on: nothing is emailed because a
        // deploy happened.
        $now = now();
        DB::table('lifecycle_campaigns')->insert([
            [
                'key' => 'no_book_3d',
                'name' => 'No book three days after signing up',
                'trigger' => 'no_book',
                'delay_days' => 3,
                'subject' => 'Your first book is one click away',
                'body' => "You signed up for Écrivain a few days ago but have not started a book yet.\n\nStart with a title, or import a manuscript you already have: chapters, footnotes and images come across as they are.",
                'cta_label' => 'Start a book',
                'enabled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'key' => 'inactive_14d',
                'name' => 'Two weeks without writing',
                'trigger' => 'inactive',
                'delay_days' => 14,
                'subject' => 'Your book is where you left it',
                'body' => "It has been a couple of weeks since you last wrote in Écrivain. Everything is saved exactly as you left it.\n\nEven ten minutes keeps a book moving.",
                'cta_label' => 'Open your books',
                'enabled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    /** The catalogue as config/billing.php describes it today. */
    private function seedPlans(): void
    {
        $now = now();
        $sort = 0;

        foreach ((array) config('billing.plans') as $key => $plan) {
            $priceId = config("billing.prices.{$key}") ?: null;
            $cents = (int) round(((float) ($plan['price'] ?? 0)) * 100);

            $id = DB::table('plans')->insertGetId([
                'key' => $key,
                'name' => $plan['name'],
                'price_cents' => $cents,
                'currency' => 'usd',
                'credits' => (int) ($plan['credits'] ?? 0),
                'books' => $plan['books'] ?? null,
                'seats' => $plan['seats'] ?? null,
                'formats' => json_encode(array_values($plan['formats'] ?? [])),
                'tier' => (int) ($plan['tier'] ?? 0),
                'visibility' => 'public',
                'sort' => $sort++,
                'stripe_price_id' => $priceId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('plan_prices')->insert([
                'plan_id' => $id,
                'amount_cents' => $cents,
                'currency' => 'usd',
                'stripe_price_id' => $priceId,
                'active_from' => $now,
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lifecycle_sends');
        Schema::dropIfExists('lifecycle_campaigns');
        Schema::dropIfExists('discount_redemptions');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_code_id');
            $table->dropColumn('discount_cents');
        });

        Schema::dropIfExists('discount_codes');
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcasts');
        Schema::dropIfExists('audiences');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['signup_source']);
            $table->dropColumn(['signup_source', 'signup_medium', 'signup_campaign', 'signup_referrer', 'signup_landing']);
        });

        Schema::dropIfExists('payouts');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('subscription_cancellations');

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['current_period_end', 'cancel_at_period_end', 'past_due_since']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['plan']);
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_plan_check CHECK (plan IN ('free','author','studio'))");

        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
    }
};
