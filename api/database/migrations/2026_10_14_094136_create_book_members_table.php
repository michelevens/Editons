<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who else can see a book, and what they may do with it.
 *
 * Membership is per book rather than per account. An author may want a
 * clinical reviewer on one title and nowhere near another, and a workspace-
 * wide role cannot express that.
 *
 * The invited person may not have an account yet, which is the normal case —
 * you invite an email address, not a user. So the email is the identity until
 * they accept, and user_id stays null until there is somebody to point at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('book_id')->constrained()->cascadeOnDelete();

            // Null until the invitation is accepted. An invite to someone who
            // has not signed up is still a real invite.
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('email');

            // editor | reviewer | viewer
            //
            // The owner is NOT a row here. Ownership lives on books.user_id,
            // and duplicating it would create the possibility of a book whose
            // owner row says one thing and whose owner column says another.
            $table->string('role')->default('viewer');

            // invited | active | revoked
            $table->string('status')->default('invited');

            // Single-use and expiring. A link that works forever is a
            // credential, and this one travels by email.
            $table->string('invite_token', 64)->nullable()->unique();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();

            $table->foreignId('invited_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One membership per address per book. Without this, inviting the
            // same person twice silently creates two rows with two roles, and
            // which one applies becomes a question of ordering.
            $table->unique(['book_id', 'email']);
            $table->index(['book_id', 'status']);
        });

        DB::statement(
            "ALTER TABLE book_members ADD CONSTRAINT book_members_role_check
             CHECK (role IN ('editor','reviewer','viewer'))"
        );

        DB::statement(
            "ALTER TABLE book_members ADD CONSTRAINT book_members_status_check
             CHECK (status IN ('invited','active','revoked'))"
        );

        // An accepted membership must name the person who accepted it.
        DB::statement(
            "ALTER TABLE book_members ADD CONSTRAINT book_members_active_has_user_check
             CHECK (status <> 'active' OR user_id IS NOT NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('book_members');
    }
};
