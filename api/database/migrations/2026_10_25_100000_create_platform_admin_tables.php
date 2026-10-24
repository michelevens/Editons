<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The platform owner's console (ADR — super-admin console).
 *
 * Until now Écrivain had no notion of anybody above an author: no admin
 * role, no second factor, nowhere to see the platform as a whole. This adds
 * the minimum that console needs to be safe rather than merely useful:
 *
 *  - platform roles on users, granted only from the shell (platform:admin);
 *  - TOTP two-factor for every account, required for the platform's own;
 *  - suspension, and a "signed out everywhere" marker that does not depend
 *    on the session driver;
 *  - complimentary plans with an end date;
 *  - the admin audit log, impersonation sessions, sign-in history, internal
 *    notes, typed platform settings and a daily metrics snapshot;
 *  - credit-ledger kinds for adjustments made by staff, signed like the rest
 *    (ADR-0010), and an impersonator column on the rows that record who did
 *    something to a manuscript or a balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // owner | support. Null for every author. Never mass-assignable.
            $table->string('platform_role', 16)->nullable();

            // TOTP (RFC 6238). The secret and the recovery codes are stored
            // encrypted with APP_KEY; the codes are also hashed inside that,
            // so even a decrypted copy cannot be replayed.
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            // The last 30-second step a code was accepted for: the same code
            // cannot be used twice, even inside its own window.
            $table->unsignedBigInteger('two_factor_last_step')->nullable();

            $table->timestamp('suspended_at')->nullable();
            $table->text('suspended_reason')->nullable();

            // Any session that began before this is signed out on its next
            // request. Works whatever SESSION_DRIVER is, unlike deleting rows
            // from the sessions table.
            $table->timestamp('sessions_revoked_at')->nullable();

            // A plan given rather than bought. `plan` still holds the plan the
            // limits come from; these say it was complimentary and until when.
            $table->timestamp('complimentary_since')->nullable();
            $table->timestamp('complimentary_until')->nullable();
        });

        DB::statement(
            "ALTER TABLE users ADD CONSTRAINT users_platform_role_check
             CHECK (platform_role IS NULL OR platform_role IN ('owner','support'))"
        );

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            // Null for the console and the scheduler ("system").
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('impersonator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['actor_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('impersonator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('target_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->timestamp('started_at');
            // Hard limit. Never extended: a new session needs a new reason.
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            // stopped | expired | signed_out | revoked
            $table->string('end_reason', 24)->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['target_id', 'started_at']);
            $table->index(['impersonator_id', 'started_at']);
        });

        Schema::create('user_logins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // password | two_factor | recovery_code | registration | impersonation
            $table->string('method', 24);
            $table->boolean('impersonated')->default(false);
            $table->foreignId('impersonator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('admin_notes', function (Blueprint $table) {
            $table->id();
            // The account the note is about.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('pinned')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'pinned', 'created_at']);
        });

        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->jsonb('value')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });

        // One row per day, written by platform:snapshot-metrics. History
        // starts the day this ships: nothing is back-filled, because a guess
        // at last month drawn as a line is indistinguishable from data.
        Schema::create('platform_metrics', function (Blueprint $table) {
            $table->id();
            $table->date('day')->unique();
            $table->jsonb('figures');
            $table->timestamp('captured_at');
        });

        // Who was really at the keyboard when these rows were written.
        foreach (['manuscript_versions', 'comments', 'credit_transactions', 'editorial_reviews'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('impersonator_id')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        // Staff adjustments to the credit ledger, signed like the rest.
        DB::statement('ALTER TABLE credit_transactions DROP CONSTRAINT IF EXISTS credit_transactions_kind_check');
        DB::statement(
            "ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_kind_check
             CHECK (kind IN ('grant','spend','refund','purchase','expiry','admin_grant','admin_deduct'))"
        );
        DB::statement('ALTER TABLE credit_transactions DROP CONSTRAINT IF EXISTS credit_transactions_sign_check');
        DB::statement(
            "ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_sign_check
             CHECK (
                 (kind = 'spend' AND amount < 0)
                 OR (kind = 'expiry' AND amount <= 0)
                 OR (kind = 'admin_deduct' AND amount < 0)
                 OR (kind IN ('grant','refund','purchase','admin_grant') AND amount > 0)
             )"
        );
    }

    public function down(): void
    {
        DB::statement("DELETE FROM credit_transactions WHERE kind IN ('admin_grant','admin_deduct')");
        DB::statement('ALTER TABLE credit_transactions DROP CONSTRAINT IF EXISTS credit_transactions_kind_check');
        DB::statement(
            "ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_kind_check
             CHECK (kind IN ('grant','spend','refund','purchase','expiry'))"
        );
        DB::statement('ALTER TABLE credit_transactions DROP CONSTRAINT IF EXISTS credit_transactions_sign_check');
        DB::statement(
            "ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_sign_check
             CHECK (
                 (kind = 'spend' AND amount < 0)
                 OR (kind = 'expiry' AND amount <= 0)
                 OR (kind IN ('grant','refund','purchase') AND amount > 0)
             )"
        );

        foreach (['manuscript_versions', 'comments', 'credit_transactions', 'editorial_reviews'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('impersonator_id');
            });
        }

        Schema::dropIfExists('platform_metrics');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('admin_notes');
        Schema::dropIfExists('user_logins');
        Schema::dropIfExists('impersonation_sessions');
        Schema::dropIfExists('admin_audit_logs');

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_platform_role_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'platform_role', 'two_factor_secret', 'two_factor_recovery_codes',
                'two_factor_confirmed_at', 'two_factor_last_step', 'suspended_at',
                'suspended_reason', 'sessions_revoked_at', 'complimentary_since',
                'complimentary_until',
            ]);
        });
    }
};
