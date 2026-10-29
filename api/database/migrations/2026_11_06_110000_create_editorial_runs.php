<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Queued review runs (ADR — Style guides and review options).
 *
 * A range or whole-book review runs on the worker, one section at a time,
 * so a real provider's run over many chapters never hangs on a web
 * request. The row is the run's progress and its record: the sections in
 * order, which are done, findings and cost so far, and why it stopped.
 * The rules the agents get are frozen on the row when it is queued.
 *
 * One active whole-book run per book, held by a partial unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('agent', 40);
            $table->string('scope', 20);
            $table->string('status', 20)->default('queued'); // queued | running | completed | stopped | cancelled | failed
            $table->jsonb('section_ids');
            $table->jsonb('done_ids')->default('[]');
            $table->jsonb('context'); // style, disabled rules, focus, sensitivity, guide version
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('findings')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('credits_spent')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->jsonb('estimate')->nullable();
            $table->string('stop_reason', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['book_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX editorial_runs_one_active_book ON editorial_runs (book_id) WHERE scope = 'book' AND status IN ('queued', 'running')");
    }

    public function down(): void
    {
        Schema::dropIfExists('editorial_runs');
    }
};
