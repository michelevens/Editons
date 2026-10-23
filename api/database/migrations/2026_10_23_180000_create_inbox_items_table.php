<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The account inbox (App\Inbox\Inbox): one row per thing that happened to
 * somebody, written where it happens. Read, done and snoozed are the
 * recipient's own state, which is why this is a table of its own rather
 * than a query over comments, exports and reviews.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 40);
            $table->string('title');
            $table->text('body')->nullable();
            // Where tapping it goes: a pane of the book, and what in it.
            $table->json('target')->nullable();
            // Repeats of one thing (a chapter moved twice, seven agents over
            // one chapter) update one row instead of stacking up.
            $table->string('group_key', 120)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'done_at', 'occurred_at']);
            $table->index(['user_id', 'group_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_items');
    }
};
