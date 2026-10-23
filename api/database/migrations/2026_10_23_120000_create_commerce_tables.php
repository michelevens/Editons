<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct sales: products, orders, order items, licenses, and the two
 * bookkeeping tables that keep webhooks and statements from repeating.
 *
 * Money is integer minor units (cents), with the currency beside it, as
 * Stripe reports it. Nothing is a float.
 *
 * Order items copy the title, price and formats at the moment of sale, and
 * licenses copy their terms: an author editing a product later must not
 * change what an earlier buyer paid for or agreed to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The public storefront's address. Opaque on purpose: not the
            // user id (which counts accounts) and not a name (an author who
            // publishes anonymously has none to show).
            $table->string('store_handle', 32)->nullable()->unique();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // One product per book. An edition is its own book, so each
            // edition can be its own product.
            $table->foreignId('book_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('price_cents');
            $table->string('currency', 3);
            $table->json('formats');
            $table->string('license', 32);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'published_at']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            // pending → paid → (partially_)refunded; pending → cancelled.
            $table->string('status', 24)->default('pending');
            $table->foreignId('buyer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('buyer_email')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_country', 2)->nullable();
            $table->string('currency', 3);
            $table->unsignedInteger('total_cents');
            $table->unsignedInteger('fee_cents')->default(0);
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->string('payment_method')->nullable();
            $table->string('stripe_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'paid_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // Kept when a product or book is deleted: the sale happened.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->json('formats');
            // Named apart from the license() relation to the issued license.
            $table->string('license_type', 32);
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('fee_cents')->default(0);
            $table->unsignedInteger('royalty_cents')->default(0);
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->timestamps();

            $table->index(['seller_id', 'created_at']);
        });

        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40)->unique();
            $table->string('license', 32);
            $table->text('terms');
            // active | revoked (a full refund revokes it).
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        // Stripe delivers each event at least once, and retries. One row per
        // (event, purpose) claims the work; a second delivery finds it.
        Schema::create('processed_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('event_id');
            $table->string('purpose', 32);
            $table->string('type');
            $table->timestamp('created_at')->nullable();

            $table->unique(['event_id', 'purpose']);
        });

        // One statement per author, month and currency, ever: re-running the
        // command for a month that was already sent sends nothing.
        Schema::create('royalty_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->string('currency', 3);
            $table->unsignedInteger('units');
            $table->integer('royalty_cents');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'month', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('royalty_statements');
        Schema::dropIfExists('processed_webhooks');
        Schema::dropIfExists('licenses');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('products');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['store_handle']);
            $table->dropColumn('store_handle');
        });
    }
};
