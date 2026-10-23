<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for foreign keys that had none.
 *
 * PostgreSQL does not index a foreign key by itself. Without these, every
 * user deletion scans manuscript_versions, comments, citations and the rest
 * for its ON DELETE SET NULL, deleting a part scans every section for its
 * children, and the shelf filters book_members by user_id on every load.
 *
 * Plain CREATE INDEX: Laravel runs a PostgreSQL migration in a transaction,
 * where CONCURRENTLY is not allowed, and these tables are small enough
 * today (a few thousand rows) for the brief lock not to matter.
 */
return new class extends Migration
{
    /** table => list of column lists */
    private const INDEXES = [
        'books' => [['last_section_id'], ['parent_book_id']],
        'manuscript_sections' => [['parent_id'], ['reviewer_id']],
        'manuscript_versions' => [['user_id']],
        'editorial_reviews' => [['user_id']],
        'editorial_findings' => [['resolved_by']],
        'research_sources' => [['added_by'], ['verified_by']],
        'citations' => [['created_by']],
        'book_members' => [['user_id', 'status'], ['invited_by']],
        'comments' => [['user_id'], ['resolved_by']],
        'book_exports' => [['user_id']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $columns) {
                if (Schema::hasIndex($table, $columns)) {
                    continue;
                }

                Schema::table($table, fn (Blueprint $t) => $t->index($columns));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $columns) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($columns));
            }
        }
    }
};
