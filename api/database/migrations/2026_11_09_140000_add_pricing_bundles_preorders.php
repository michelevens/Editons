<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Storefront pricing (ADR — per-format pricing, sales, author codes,
 * bundles and pre-orders; App\Commerce\Pricing).
 *
 * Everything here is nullable or new: a product nobody touches keeps one
 * price for everything it delivers, no sale, and sells straight away.
 *
 *   products.format_prices   {epub: cents, pdf: cents}: one format bought
 *                            on its own. Null: the product sells as one
 *                            package at price_cents, as before.
 *   products.sales           {offer: {cents, starts_at, ends_at}}, UTC.
 *   products.release_at      a pre-order's release; null once released or
 *                            never a pre-order.
 *   discount_codes.seller_id an author's own code, for their own books
 *                            only; null for the owner's site-wide codes.
 *   bundles, bundle_items    several of one seller's products at one price.
 *   order_items              which offer was bought, the bundle it came
 *                            in, and when a pre-ordered item is delivered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('format_prices')->nullable();
            $table->json('sales')->nullable();
            $table->timestamp('release_at')->nullable();
        });

        Schema::table('discount_codes', function (Blueprint $table) {
            $table->foreignId('seller_id')->nullable()->constrained('users')->cascadeOnDelete();
            // Null: every product (and bundle) the seller has.
            $table->json('product_ids')->nullable();
            $table->json('bundle_ids')->nullable();
            $table->dropUnique(['code']);
        });

        // A code is unique per seller (and once among the site-wide codes),
        // so one author's SUMMER says nothing about another's.
        DB::statement('CREATE UNIQUE INDEX discount_codes_seller_code_unique ON discount_codes (COALESCE(seller_id, 0), upper(code))');

        Schema::create('bundles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('price_cents');
            $table->string('currency', 3);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'published_at']);
        });

        Schema::create('bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);

            $table->unique(['bundle_id', 'product_id']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            // all | epub | pdf | paperback. Null on sales made before.
            $table->string('offer', 16)->nullable();
            // The list price before a sale or bundle split, for reports.
            $table->unsignedInteger('list_price_cents')->nullable();
            $table->foreignId('bundle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('bundle_title')->nullable();
            // A pre-order: no licence until release_at, then delivered_at.
            $table->timestamp('release_at')->nullable();
            $table->timestamp('delivered_at')->nullable();

            $table->index(['release_at', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['release_at', 'delivered_at']);
            $table->dropConstrainedForeignId('bundle_id');
            $table->dropColumn(['offer', 'list_price_cents', 'bundle_title', 'release_at', 'delivered_at']);
        });

        Schema::dropIfExists('bundle_items');
        Schema::dropIfExists('bundles');

        DB::statement('DROP INDEX IF EXISTS discount_codes_seller_code_unique');

        Schema::table('discount_codes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seller_id');
            $table->dropColumn(['product_ids', 'bundle_ids']);
            $table->unique('code');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['format_prices', 'sales', 'release_at']);
        });
    }
};
