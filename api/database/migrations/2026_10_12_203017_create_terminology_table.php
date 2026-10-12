<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The book's controlled vocabulary: names, terms and their agreed spelling.
 *
 * This is what makes consistency checking work ACROSS chapters rather than
 * within one. A per-chapter pass can only catch a contradiction that happens
 * to sit inside the same chapter; the real failure in a long manuscript is
 * calling someone "Jon" in chapter 2 and "John" in chapter 19.
 *
 * The entries are author-declared rather than inferred. A machine guessing
 * which spelling is canonical would eventually "correct" a deliberate one,
 * and in a book about, say, Haitian Creole orthography that is not a small
 * mistake.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terminology', function (Blueprint $table) {
            $table->id();

            $table->foreignId('book_id')->constrained()->cascadeOnDelete();

            // character | place | term | organization | other
            $table->string('kind')->default('term');

            // The spelling that is correct for this book.
            $table->string('canonical');

            // Spellings that are wrong HERE and should be flagged on sight,
            // stored as a JSON array. jsonb so Postgres can index it later
            // if the variant lists grow large enough to be worth searching.
            $table->jsonb('variants')->default('[]');

            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['book_id', 'canonical']);
            $table->index(['book_id', 'kind']);
        });

        DB::statement(
            "ALTER TABLE terminology ADD CONSTRAINT terminology_kind_check
             CHECK (kind IN ('character','place','term','organization','other'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('terminology');
    }
};
