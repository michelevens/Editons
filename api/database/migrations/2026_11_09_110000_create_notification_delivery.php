<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How and when notifications reach somebody (ADR — Notification frequency,
 * channels, mutes and digests).
 *
 * - users.notification_settings: overrides only, like email_preferences;
 *   App\Mail\NotificationSettings resolves the defaults (instant email,
 *   inbox on, nothing muted, no quiet hours, digests Monday 8:00).
 * - held_emails: an email kept back for a daily or weekly digest, or until
 *   the recipient's quiet hours end. `digest_id` is set when a digest takes
 *   it and `sent_at` when it left (or was dropped), so nothing goes twice.
 * - email_digests: one row per person, kind and period. The unique key is
 *   the idempotency: a retry finds the row and does not send again.
 *
 * The weekly progress email becomes opt-in. Until now it was on unless
 * switched off, and the profile form saved every switch, so a stored
 * `digest: true` cannot be told apart from a switch nobody touched. Those
 * are set aside as `digest_legacy` (ignored when reading; down() puts them
 * back) rather than deleted. A stored `false` is kept: it already says off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_settings')->nullable();
        });

        Schema::create('email_digests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // daily, weekly (held notifications) or progress (weekly writing summary)
            $table->string('kind', 16);
            // The local date of the scheduled moment the digest belongs to.
            $table->string('period', 16);
            $table->unsignedInteger('items')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'kind', 'period']);
        });

        Schema::create('held_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category', 32);
            // daily, weekly or quiet
            $table->string('mode', 16);
            // What the digest lists: the email's subject and button, written
            // in the recipient's language when it was held.
            $table->string('subject', 300);
            $table->string('url', 1000)->nullable();
            // A quiet-hours hold sends the email itself when they end.
            $table->longText('mailable')->nullable();
            $table->timestamp('release_at')->nullable();
            $table->foreignId('email_digest_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'mode', 'sent_at']);
            $table->index(['mode', 'sent_at', 'release_at']);
        });

        DB::table('users')->whereNotNull('email_preferences')->orderBy('id')->each(function ($user) {
            $prefs = json_decode($user->email_preferences, true) ?: [];

            if (($prefs['digest'] ?? null) === true) {
                unset($prefs['digest']);
                $prefs['digest_legacy'] = true;
                DB::table('users')->where('id', $user->id)->update(['email_preferences' => json_encode($prefs)]);
            }
        });
    }

    public function down(): void
    {
        DB::table('users')->whereNotNull('email_preferences')->orderBy('id')->each(function ($user) {
            $prefs = json_decode($user->email_preferences, true) ?: [];

            if (array_key_exists('digest_legacy', $prefs)) {
                $prefs['digest'] = (bool) $prefs['digest_legacy'];
                unset($prefs['digest_legacy']);
                DB::table('users')->where('id', $user->id)->update(['email_preferences' => json_encode($prefs)]);
            }
        });

        Schema::dropIfExists('held_emails');
        Schema::dropIfExists('email_digests');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_settings');
        });
    }
};
