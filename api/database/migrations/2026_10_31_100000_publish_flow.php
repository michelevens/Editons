<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publish in three steps (ADR — publish in three steps).
 *
 * - books.publish_plan: the channels chosen on the Publish screen and the
 *   KDP values the author edited there (keywords, categories, audience).
 * - book_channels.external_id: the store's own id once the author has
 *   submitted (an ASIN at Amazon), and who said so.
 * - products: the paperback a storefront sells through Lulu, its price and
 *   the Lulu file validation it passed (`print`).
 * - orders: shipping for a paperback, the address encrypted at rest.
 * - print_jobs: one Lulu print job per paperback order item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->jsonb('publish_plan')->nullable();
        });

        Schema::table('book_channels', function (Blueprint $table) {
            $table->string('external_id', 40)->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('paperback_price_cents')->nullable();
            $table->jsonb('print')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('shipping_cents')->default(0);
            // Encrypted (Order casts): a buyer's home address and phone.
            $table->text('shipping_address')->nullable();
        });

        Schema::table('order_items', function (Blueprint $table) {
            // What printing one copy was quoted at, taken out of the royalty.
            $table->unsignedInteger('print_cost_cents')->nullable();
        });

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 16)->default('lulu');
            // pending (not sent yet), then Lulu's own status names, or failed.
            $table->string('status', 24)->default('pending');
            $table->string('external_id', 40)->nullable()->unique();
            $table->string('pod_package_id', 40);
            $table->unsignedInteger('page_count');
            $table->foreignId('interior_export_id')->nullable()->constrained('book_exports')->nullOnDelete();
            $table->foreignId('cover_export_id')->nullable()->constrained('book_exports')->nullOnDelete();
            $table->unsignedInteger('cost_cents')->nullable();
            $table->string('cost_currency', 3)->nullable();
            $table->jsonb('tracking')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('polled_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('buyer_notified_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'polled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');

        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn('print_cost_cents'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn(['shipping_cents', 'shipping_address']));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['paperback_price_cents', 'print']));
        Schema::table('book_channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropColumn('external_id');
        });
        Schema::table('books', fn (Blueprint $table) => $table->dropColumn('publish_plan'));
    }
};
