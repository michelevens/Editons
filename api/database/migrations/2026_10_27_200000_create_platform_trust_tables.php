<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The admin console, trust and operations (ADR — console trust and operations).
 *
 *  - products: a listing taken down by Ecrivain, with who and why. Buyers
 *    keep their licences; only the public page goes;
 *  - content_reports: "Report this book" from the public storefront;
 *  - login_failures and three user_logins columns: failed sign-ins and
 *    lockouts, and whether a sign-in came from somewhere new;
 *  - data_health_runs: each run of the data checks, stored;
 *  - status_incidents and their updates: the public status page;
 *  - account_exports and account_deletions: offboarding, with the dry run
 *    taken when it was scheduled and the destruction certificate;
 *  - master_lists: owner-edited lists that used to be constants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('taken_down_at')->nullable();
            $table->text('taken_down_reason')->nullable();
            $table->foreignId('taken_down_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            // What was reported: a product page, or an author's shelf.
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_handle', 32)->nullable();
            $table->string('category', 32);
            $table->text('details')->nullable();
            // Optional, for a reply. Never shown to the author reported.
            $table->string('reporter_email')->nullable();
            // An HMAC of the IP: enough to see one person filing twenty
            // reports, not enough to find them.
            $table->string('reporter_ip_hash', 64)->nullable();
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('open'); // open | resolved | dismissed
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        DB::statement("ALTER TABLE content_reports ADD CONSTRAINT content_reports_status_check CHECK (status IN ('open','resolved','dismissed'))");

        Schema::create('login_failures', function (Blueprint $table) {
            $table->id();
            // HMAC of the lower-cased address typed: the same address always
            // gives the same hash, and the table never holds an address
            // somebody mistyped (or somebody else's being tried).
            $table->string('email_hash', 64)->nullable();
            // Set when the address has an account. Staff-only.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 16); // password | two_factor | lockout
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['email_hash', 'created_at']);
            $table->index(['ip', 'created_at']);
        });

        DB::statement("ALTER TABLE login_failures ADD CONSTRAINT login_failures_kind_check CHECK (kind IN ('password','two_factor','lockout'))");

        Schema::table('user_logins', function (Blueprint $table) {
            // The IPv4 /16 (or IPv6 /32) the sign-in came from, and the
            // country when the edge said (Cloudflare's CF-IPCountry).
            $table->string('network', 64)->nullable();
            $table->string('country', 2)->nullable();
            // First sign-in from that country — or, without a country, from
            // that network. Never set on the account's first sign-in.
            $table->boolean('new_location')->default(false);
        });

        Schema::create('data_health_runs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger', 16); // schedule | manual
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('results');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->index('started_at');
        });

        Schema::create('status_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('severity', 16); // minor | major | critical | maintenance
            $table->string('status', 16); // investigating | identified | monitoring | resolved
            $table->jsonb('components');
            // Also in the in-app banner, while it is open.
            $table->boolean('show_in_banner')->default(false);
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['resolved_at', 'started_at']);
        });

        Schema::create('status_incident_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('status_incident_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16);
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('users', function (Blueprint $table) {
            // An account deleted by offboarding. The row stays, emptied of
            // everything personal, so the sales other people bought from it
            // (order_items.seller_id) and the audit log keep their subject.
            $table->timestamp('offboarded_at')->nullable();
        });

        Schema::create('account_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('queued'); // queued | ready | failed | expired
            $table->string('path')->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->jsonb('contents')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->text('error')->nullable();
            // Email the account a download link once it is ready.
            $table->boolean('email_account')->default(false);
            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('account_deletions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // So the certificate can name the account after it is emptied.
            $table->string('email_hash', 64);
            $table->string('status', 16); // scheduled | cancelled | completed
            $table->text('reason');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // The dry run as it stood when this was scheduled.
            $table->jsonb('plan');
            $table->timestamp('scheduled_for');
            // Whether scheduling suspended the account (and cancelling should
            // lift it), or it was suspended already.
            $table->boolean('suspended_by_us')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('certificate')->nullable();
            $table->string('certificate_sha256', 64)->nullable();
            $table->string('certificate_path')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
        });

        DB::statement("ALTER TABLE account_deletions ADD CONSTRAINT account_deletions_status_check CHECK (status IN ('scheduled','cancelled','completed'))");
        // One open deletion per account.
        DB::statement("CREATE UNIQUE INDEX account_deletions_one_scheduled ON account_deletions (user_id) WHERE status = 'scheduled'");

        Schema::create('master_lists', function (Blueprint $table) {
            // A key of App\Platform\Trust\MasterData::LISTS.
            $table->string('key', 40)->primary();
            $table->jsonb('items');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_lists');
        Schema::dropIfExists('account_deletions');
        Schema::dropIfExists('account_exports');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('offboarded_at'));
        Schema::dropIfExists('status_incident_updates');
        Schema::dropIfExists('status_incidents');
        Schema::dropIfExists('data_health_runs');
        Schema::table('user_logins', fn (Blueprint $table) => $table->dropColumn(['network', 'country', 'new_location']));
        Schema::dropIfExists('login_failures');
        Schema::dropIfExists('content_reports');
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('taken_down_by');
            $table->dropColumn(['taken_down_at', 'taken_down_reason']);
        });
    }
};
