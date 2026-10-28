<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The customisable writing workspace (ADR — customisable writing workspace).
 *
 * - users.workspace: the author's own layout and their named presets,
 *   validated by App\Workspace\WorkspaceSchema; null means the default.
 * - book_workspaces: a layout one author keeps for one book only ("use this
 *   layout for this book only"). Per person: a co-author's arrangement of
 *   the same book is theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('workspace')->nullable();
        });

        Schema::create('book_workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->jsonb('settings');
            $table->timestamps();

            $table->unique(['user_id', 'book_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_workspaces');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('workspace');
        });
    }
};
