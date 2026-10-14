<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Launch Planner.
 *
 * The timeline itself is not stored: the tasks are fixed and each one's date
 * is an offset from the launch date, so storing eight rows per book would be
 * storing a calculation. What is stored is the launch date, which tasks are
 * done, and the advance readers — the parts that are the author's own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->date('launch_date')->nullable()->after('rights');

            // Task keys the author has ticked. jsonb rather than a row per
            // task: the set is fixed, small, and read as a whole by one
            // screen, and nothing ever queries "who has done task 4".
            $table->jsonb('launch_tasks')->nullable()->after('launch_date');
        });

        // Advance readers. A table rather than jsonb because each carries its
        // own state and is contacted individually, and "has this reader
        // reviewed" is a question worth asking across books later.
        Schema::create('advance_readers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('email');

            // invited | sent | reading | reviewed | declined
            $table->string('status')->default('invited');

            $table->timestamp('copy_sent_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            // One invitation per person per book. Without it a double submit
            // emails somebody twice and shows them twice.
            $table->unique(['book_id', 'email']);
        });

        DB::statement(
            "ALTER TABLE advance_readers ADD CONSTRAINT advance_readers_status_check
             CHECK (status IN ('invited','sent','reading','reviewed','declined'))"
        );

        // A reader who has reviewed must have been sent a copy. Otherwise the
        // screen can show a review for a book nobody received.
        DB::statement(
            "ALTER TABLE advance_readers ADD CONSTRAINT advance_readers_sequence_check
             CHECK (reviewed_at IS NULL OR copy_sent_at IS NOT NULL)"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_readers');

        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['launch_date', 'launch_tasks']);
        });
    }
};
