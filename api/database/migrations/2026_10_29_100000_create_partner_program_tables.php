<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Écrivain Partners: the affiliate program (ADR — partner program).
 *
 *  - partners: one per account that applied, with its branded page (handle,
 *    logo, tagline, bio, accent, links), the agreement it accepted, its
 *    payout details (encrypted) and its status;
 *  - partner_handles: handles a partner has left behind, so old links and
 *    printed QR codes keep working (and nobody else can take them);
 *  - partner_shelf_items: the books on a partner's page, in order, each with
 *    a note;
 *  - partner_clicks: one per visitor per partner per day, hashed (never a
 *    raw IP or user agent);
 *  - partner_commissions: the ledger. One `sale` row per order item, plus
 *    signed `adjustment` and `forfeit` rows; the rate and the amounts are
 *    copied at the time, so history is never recomputed from settings;
 *  - partner_blocks, partner_sellers, partner_invitations: a seller blocking
 *    a partner, the sellers an invited (restricted) partner may sell, and
 *    the invitations themselves;
 *  - partner_statements: monthly statements sent, so a re-run sends none.
 *
 * And columns on existing tables: the seller's opt-in and rate on products,
 * the attribution on orders, the commission on order items, which kind of
 * payout a payout is (royalty or partner: one ledger), a partner on a
 * discount code, and a partner on a content report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Stable, for ?partner= links and printed codes. Never changes.
            $table->string('code', 12)->unique();
            $table->string('handle', 30)->unique();
            $table->timestamp('handle_changed_at')->nullable();
            // applied | approved | declined | suspended
            $table->string('status', 16)->default('applied');

            // The application.
            $table->string('display_name', 80);
            $table->string('country', 2)->nullable();
            // school | bookstore | social | community | other
            $table->string('promotion_type', 16);
            $table->text('promotion_note')->nullable();
            $table->json('links')->nullable();
            $table->string('agreement_version', 16);
            $table->timestamp('agreement_accepted_at');
            // Invited by an author: may sell only that author's books
            // (partner_sellers) until staff open the whole catalogue.
            $table->boolean('restricted')->default(false);

            // The page.
            $table->string('tagline', 80)->nullable();
            $table->text('bio')->nullable();
            $table->string('accent', 7)->nullable();
            $table->string('page_locale', 2)->default('en');
            $table->json('socials')->nullable();
            $table->string('logo_path')->nullable();

            // Payouts: method and a free-text reference, encrypted. No tax
            // ids are collected; staff note that a form was received.
            $table->string('payout_method', 24)->nullable();
            $table->text('payout_details')->nullable();
            $table->timestamp('payout_details_updated_at')->nullable();
            $table->timestamp('tax_form_received_at')->nullable();
            $table->foreignId('tax_form_marked_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('suspend_reason')->nullable();
            // A suspended partner's balance is frozen until staff release it
            // (it may then be paid) or forfeit it.
            $table->timestamp('balance_released_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('partner_handles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->string('handle', 30)->unique();
            $table->timestamp('retired_at');
        });

        Schema::create('partner_shelf_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('note', 200)->nullable();
            $table->timestamps();

            $table->unique(['partner_id', 'product_id']);
        });

        Schema::create('partner_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            // HMACs with the app key: enough to count a visitor once a day
            // and to compare with the partner's own sign-ins, not to find
            // anybody.
            $table->string('visitor_hash', 64);
            $table->string('ip_hash', 64);
            $table->string('ua_hash', 64)->nullable();
            $table->date('day');
            // page | link | param | qr
            $table->string('source', 8);
            $table->timestamp('created_at')->nullable();

            $table->unique(['partner_id', 'visitor_hash', 'day']);
            $table->index(['partner_id', 'created_at']);
        });

        Schema::create('partner_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('currency', 3);
            // sale | adjustment | forfeit
            $table->string('kind', 12)->default('sale');
            // Basis points (2500 = 25 %), as it was at checkout.
            $table->unsignedSmallInteger('rate_bps')->default(0);
            // The item's net when it was paid: price after discount, less
            // its share of the payment fee.
            $table->integer('base_cents')->default(0);
            // Signed: adjustments and forfeits are negative.
            $table->integer('amount_cents');
            // Taken back by a refund before the commission was paid.
            $table->integer('reversed_cents')->default(0);
            // pending | approved | paid | reversed
            $table->string('status', 12)->default('pending');
            // code | cookie
            $table->string('source', 8)->nullable();
            $table->string('buyer_country', 2)->nullable();
            $table->timestamp('hold_until')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('payout_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['partner_id', 'status']);
            $table->index(['status', 'hold_until']);
            $table->index('seller_id');
        });

        // One sale commission per order item, whatever repeats.
        DB::statement("CREATE UNIQUE INDEX partner_commissions_sale_unique ON partner_commissions (order_item_id) WHERE kind = 'sale'");

        Schema::create('partner_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['seller_id', 'partner_id']);
        });

        Schema::create('partner_sellers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['partner_id', 'seller_id']);
        });

        Schema::create('partner_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('email');
            // Only the hash: the token is in the email and nowhere else.
            $table->string('token_hash', 64)->unique();
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['seller_id', 'email']);
        });

        Schema::create('partner_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->string('currency', 3);
            $table->integer('amount_cents');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['partner_id', 'month', 'currency']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('partner_enabled')->default(false);
            $table->unsignedSmallInteger('partner_rate_bps')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            // The partner the order was attributed to, how, and — when no
            // commission is due — why (self_purchase, seller, suspended…).
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
            $table->string('partner_source', 8)->nullable();
            $table->string('partner_refusal', 24)->nullable();
            // The checkout's IP as an HMAC, for the console's fraud signals.
            $table->string('partner_ip_hash', 64)->nullable();

            $table->index('partner_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            // The rate at checkout, and the commission at payment. The
            // item's royalty_cents is already net of it.
            $table->unsignedSmallInteger('partner_rate_bps')->nullable();
            $table->unsignedInteger('partner_commission_cents')->default(0);
        });

        Schema::table('payouts', function (Blueprint $table) {
            // royalty | partner: one ledger of money paid out by hand.
            $table->string('kind', 12)->default('royalty');

            $table->index(['kind', 'user_id', 'currency']);
        });

        Schema::table('discount_codes', function (Blueprint $table) {
            // A partner's code attributes the sale even without a cookie.
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('content_reports', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_reports', fn (Blueprint $table) => $table->dropConstrainedForeignId('partner_id'));
        Schema::table('discount_codes', fn (Blueprint $table) => $table->dropConstrainedForeignId('partner_id'));
        Schema::table('payouts', function (Blueprint $table) {
            $table->dropIndex(['kind', 'user_id', 'currency']);
            $table->dropColumn('kind');
        });
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['partner_rate_bps', 'partner_commission_cents']));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['partner_id']);
            $table->dropConstrainedForeignId('partner_id');
            $table->dropColumn(['partner_source', 'partner_refusal', 'partner_ip_hash']);
        });
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['partner_enabled', 'partner_rate_bps']));

        Schema::dropIfExists('partner_statements');
        Schema::dropIfExists('partner_invitations');
        Schema::dropIfExists('partner_sellers');
        Schema::dropIfExists('partner_blocks');
        Schema::dropIfExists('partner_commissions');
        Schema::dropIfExists('partner_clicks');
        Schema::dropIfExists('partner_shelf_items');
        Schema::dropIfExists('partner_handles');
        Schema::dropIfExists('partners');
    }
};
