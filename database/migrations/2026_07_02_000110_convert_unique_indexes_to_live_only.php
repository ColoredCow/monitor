<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Uniqueness among LIVE rows only: trashed rows index as NULL (always allowed).
        DB::statement('ALTER TABLE monitors ADD UNIQUE INDEX monitors_url_active_unique ((IF(deleted_at IS NULL, url, NULL)))');
        $this->dropPlainUniqueIndexOn('monitors', 'url');

        DB::statement('ALTER TABLE organizations ADD UNIQUE INDEX organizations_slug_active_unique ((IF(deleted_at IS NULL, slug, NULL)))');
        $this->dropPlainUniqueIndexOn('organizations', 'slug');
    }

    public function down(): void
    {
        // Best effort: fails if trashed rows now duplicate a live value.
        DB::statement('ALTER TABLE monitors ADD UNIQUE INDEX monitors_url_unique (url)');
        DB::statement('ALTER TABLE monitors DROP INDEX monitors_url_active_unique');

        DB::statement('ALTER TABLE organizations ADD UNIQUE INDEX organizations_slug_unique (slug)');
        DB::statement('ALTER TABLE organizations DROP INDEX organizations_slug_active_unique');
    }

    /**
     * Drop the pre-existing single-column unique index by whatever name it
     * actually has on this database (name-agnostic, so a manually renamed
     * index on production cannot half-apply this migration). Functional
     * indexes are excluded automatically: their STATISTICS rows have a NULL
     * COLUMN_NAME, so the new *_active_unique index never matches.
     */
    private function dropPlainUniqueIndexOn(string $table, string $column): void
    {
        $indexes = DB::select(
            'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
               AND NON_UNIQUE = 0 AND INDEX_NAME != ?',
            [$table, $column, 'PRIMARY']
        );

        foreach ($indexes as $index) {
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$index->INDEX_NAME}`");
        }
    }
};
