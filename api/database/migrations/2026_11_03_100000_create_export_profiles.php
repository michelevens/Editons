<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Export profiles (ADR-0043 — Export profiles).
 *
 * - export_profiles: a named set of export options saved on one book
 *   (App\Publishing\ExportOptions). Names are unique per book whatever
 *   their case, so "Sample" and "sample" cannot both be offered.
 * - products.sample_enabled: whether the store product page links a
 *   Sample-profile export of the book as "Read a sample". Off until the
 *   author turns it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->jsonb('options');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX export_profiles_book_name_unique ON export_profiles (book_id, lower(name))');

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('sample_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('sample_enabled');
        });

        Schema::dropIfExists('export_profiles');
    }
};
