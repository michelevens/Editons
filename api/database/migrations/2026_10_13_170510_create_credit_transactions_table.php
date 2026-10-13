<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The credit ledger.
 *
 * HANDOFF.md: "credits live server-side as a ledger (credit_transactions).
 * Debit when a job is created and refund if it fails."
 *
 * Append-only, and deliberately not a `credits` integer on users. A single
 * mutable balance can only answer "how many are left"; it cannot answer "why",
 * cannot be audited after a disputed charge, and cannot be rebuilt if a refund
 * is missed. The balance here is the sum of the rows, so the two can never
 * disagree — there is only one number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Signed: grants and refunds are positive, spends negative.
            // Summing the column is then the whole balance calculation, with
            // no sign convention to get wrong at the call site.
            $table->integer('amount');

            // grant | spend | refund | purchase | expiry
            $table->string('kind');

            // The action this paid for: 'chapter_review', 'narrate', etc.
            // Free text rather than an enum because the price list will grow
            // faster than migrations should.
            $table->string('label');

            // What it was spent on, when that is a record in this system.
            $table->nullableMorphs('subject');

            // Ties a spend to its refund. A failed job refunds by writing a
            // positive row pointing at the original, so both remain visible —
            // deleting the debit would erase the evidence that it happened.
            $table->foreignId('reverses_id')->nullable()
                ->constrained('credit_transactions')->nullOnDelete();

            // Stripe's identifier for a purchase, so a replayed webhook cannot
            // grant the same pack twice.
            $table->string('external_reference')->nullable()->unique();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'kind']);
        });

        DB::statement(
            "ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_kind_check
             CHECK (kind IN ('grant','spend','refund','purchase','expiry'))"
        );

        // A spend must be negative and a grant positive. Enforced here because
        // a sign error would silently hand out credits, and the ledger is the
        // only record of what anyone is owed.
        DB::statement(
            "ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_sign_check
             CHECK (
                 (kind = 'spend' AND amount < 0)
                 OR (kind = 'expiry' AND amount <= 0)
                 OR (kind IN ('grant','refund','purchase') AND amount > 0)
             )"
        );

        // A refund must name what it reverses, and nothing else may.
        DB::statement(
            "ALTER TABLE credit_transactions ADD CONSTRAINT credit_transactions_reverses_check
             CHECK ((kind = 'refund') = (reverses_id IS NOT NULL))"
        );

        // One refund per spend. Without this, a retried failure handler would
        // refund the same job twice.
        DB::statement(
            'CREATE UNIQUE INDEX credit_transactions_one_refund_per_spend
             ON credit_transactions (reverses_id) WHERE reverses_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
