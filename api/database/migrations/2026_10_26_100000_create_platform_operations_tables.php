<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The admin console, phase 2: running the platform (ADR — console operations).
 *
 * Phase 1 let the owner look after accounts. This lets them look after the
 * platform itself, from records rather than from `railway logs`:
 *
 *  - ai_usage: one row per call to an AI provider (or to the fake one), with
 *    what it cost where the price is known; ai_controls: the kill switches;
 *  - heartbeats: what each scheduled command and the queue worker last said;
 *  - mail_logs: every email handed to the mailer, metadata always, body
 *    only when it carries no secret;
 *  - feature_flags and feature_overrides: modules switched for everybody or
 *    for one account;
 *  - support tickets, their messages and the owner's canned replies;
 *  - error_groups: server and browser errors grouped by fingerprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
            // A key of App\Platform\Ai\AiControls::FEATURES.
            $table->string('feature', 40);
            $table->string('provider', 40);
            $table->string('model', 120)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedSmallInteger('images')->nullable();
            // Null when the price is not known: an invented number in a
            // cost column is worse than an absent one.
            $table->decimal('cost_usd', 12, 6)->nullable();
            // The fake driver: sample output, nothing spent.
            $table->boolean('simulated')->default(false);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('status', 16); // ok | failed
            $table->text('error')->nullable();
            $table->foreignId('impersonator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
            $table->index(['feature', 'created_at']);
        });

        DB::statement("ALTER TABLE ai_usage ADD CONSTRAINT ai_usage_status_check CHECK (status IN ('ok','failed'))");

        Schema::create('ai_controls', function (Blueprint $table) {
            // 'all' or a feature key.
            $table->string('key', 40)->primary();
            $table->boolean('paused')->default(false);
            $table->text('reason')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            // An optional ceiling on what this account's AI calls may cost
            // in a calendar month, in USD. Null: no cap beyond the credits.
            $table->decimal('ai_monthly_cap_usd', 10, 2)->nullable();
        });

        Schema::create('heartbeats', function (Blueprint $table) {
            // A scheduled command ("exports:expire --missing") or "queue:worker".
            $table->string('key', 191)->primary();
            $table->string('kind', 16); // schedule | worker
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_status', 16)->nullable(); // running | ok | failed
            $table->text('last_error')->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->unsignedInteger('run_count')->default(0);
            // Failures in a row; a success sets it back to zero.
            $table->unsignedInteger('failure_count')->default(0);
            $table->string('host', 120)->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('mail_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to', 320);
            $table->string('mailable', 191)->nullable();
            $table->string('subject', 500)->nullable();
            $table->string('category', 40)->nullable();
            $table->string('status', 16); // sent | failed
            $table->string('provider_message_id', 191)->nullable();
            // The mail carries a secret (a reset or verification link): only
            // its metadata is kept, never its body, and it is not resent.
            $table->boolean('sensitive')->default(false);
            $table->text('body_text')->nullable();
            // Constructor arguments to build the same mailable again: models
            // as references, never their contents. Null: not resendable.
            $table->jsonb('resend_args')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('resent_from_id')->nullable()->constrained('mail_logs')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['to', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE mail_logs ADD CONSTRAINT mail_logs_status_check CHECK (status IN ('sent','failed'))");

        Schema::create('feature_flags', function (Blueprint $table) {
            // A key of App\Platform\Features::CATALOG. Stable: never renamed.
            $table->string('key', 40)->primary();
            $table->boolean('enabled');
            $table->text('reason')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('feature_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 40);
            $table->boolean('enabled');
            $table->text('reason');
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'feature']);
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 200);
            // open: waiting for staff · pending: waiting for the author · closed
            $table->string('status', 16)->default('open');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            // Attached when it was sent: plan, browser, the last error seen.
            $table->jsonb('context')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_message_at']);
            $table->index(['user_id', 'created_at']);
        });

        DB::statement("ALTER TABLE support_tickets ADD CONSTRAINT support_tickets_status_check CHECK (status IN ('open','pending','closed'))");

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('from_staff')->default(false);
            // A note between staff: never shown to the author, never emailed.
            $table->boolean('internal')->default(false);
            $table->text('body');
            // The reply came from an AI draft (edited or not) — provenance.
            $table->boolean('ai_drafted')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['support_ticket_id', 'id']);
        });

        Schema::create('canned_replies', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('error_groups', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('source', 16); // server | client
            $table->string('type', 191);
            $table->text('message');
            $table->string('location', 500)->nullable();
            $table->text('example_trace')->nullable();
            $table->string('example_url', 500)->nullable();
            $table->unsignedBigInteger('example_user_id')->nullable();
            $table->unsignedBigInteger('count')->default(0);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->string('status', 16)->default('open'); // open | resolved | ignored
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['status', 'last_seen_at']);
        });

        DB::statement("ALTER TABLE error_groups ADD CONSTRAINT error_groups_status_check CHECK (status IN ('open','resolved','ignored'))");
        DB::statement("ALTER TABLE error_groups ADD CONSTRAINT error_groups_source_check CHECK (source IN ('server','client'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('error_groups');
        Schema::dropIfExists('canned_replies');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('feature_overrides');
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('mail_logs');
        Schema::dropIfExists('heartbeats');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ai_monthly_cap_usd');
        });

        Schema::dropIfExists('ai_controls');
        Schema::dropIfExists('ai_usage');
    }
};
