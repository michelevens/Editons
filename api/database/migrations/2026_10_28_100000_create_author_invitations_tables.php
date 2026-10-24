<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations to join Écrivain, sent from the console (ADR — author
 * invitations). Platform-level: not book_members, which invites somebody to
 * one author's book.
 *
 *  - author_invitation_batches: one bulk send (pasted list or CSV), so the
 *    console can filter by it and the funnel can follow it;
 *  - author_invitations: one per address and send. Only a SHA-256 of the
 *    token is stored; the token itself exists in the emailed link and
 *    nowhere else. At most one pending invitation per address (a partial
 *    unique index, so two staff sending at once cannot both succeed);
 *  - author_invitation_opt_outs: addresses that asked not to be invited
 *    again, as an HMAC — enough to refuse, never a list of addresses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('author_invitation_batches', function (Blueprint $table) {
            $table->id();
            // Sent as utm_campaign at sign-up, so Growth's funnel shows it.
            $table->string('campaign', 150)->nullable();
            $table->string('source', 8); // paste | csv
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE author_invitation_batches ADD CONSTRAINT author_invitation_batches_source_check CHECK (source IN ('paste','csv'))");

        Schema::create('author_invitations', function (Blueprint $table) {
            $table->id();
            // Lower-cased before it is stored.
            $table->string('email', 255)->index();
            $table->string('name', 255)->nullable();
            $table->string('language', 2)->default('en');
            $table->text('note')->nullable();
            // {plan: key|null, plan_months: int|null, credits: int|null}
            $table->json('perks')->nullable();
            // What acceptance actually applied (a plan withdrawn since is skipped, and said).
            $table->json('perks_applied')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->string('status', 16)->default('pending');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('author_invitation_batches')->nullOnDelete();
            $table->string('campaign', 150)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('last_resent_at')->nullable();
            $table->unsignedSmallInteger('resend_count')->default(0);
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index('invited_by');
            $table->index('accepted_user_id');
        });

        DB::statement("ALTER TABLE author_invitations ADD CONSTRAINT author_invitations_status_check CHECK (status IN ('pending','accepted','expired','revoked','declined'))");
        DB::statement("ALTER TABLE author_invitations ADD CONSTRAINT author_invitations_language_check CHECK (language IN ('en','fr','ht'))");
        DB::statement('ALTER TABLE author_invitations ADD CONSTRAINT author_invitations_email_lower_check CHECK (email = lower(email))');
        DB::statement("CREATE UNIQUE INDEX author_invitations_one_pending ON author_invitations (email) WHERE status = 'pending'");

        Schema::create('author_invitation_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->char('email_hash', 64)->unique();
            $table->foreignId('invitation_id')->nullable()->constrained('author_invitations')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('author_invitation_opt_outs');
        Schema::dropIfExists('author_invitations');
        Schema::dropIfExists('author_invitation_batches');
    }
};
