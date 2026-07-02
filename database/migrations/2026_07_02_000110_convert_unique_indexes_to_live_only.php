<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Uniqueness among LIVE rows only: trashed rows index as NULL (always allowed).
        DB::statement('ALTER TABLE monitors ADD UNIQUE INDEX monitors_url_active_unique ((IF(deleted_at IS NULL, url, NULL)))');
        DB::statement('ALTER TABLE monitors DROP INDEX monitors_url_unique');

        DB::statement('ALTER TABLE organizations ADD UNIQUE INDEX organizations_slug_active_unique ((IF(deleted_at IS NULL, slug, NULL)))');
        DB::statement('ALTER TABLE organizations DROP INDEX organizations_slug_unique');
    }

    public function down(): void
    {
        // Best effort: fails if trashed rows now duplicate a live value.
        DB::statement('ALTER TABLE monitors ADD UNIQUE INDEX monitors_url_unique (url)');
        DB::statement('ALTER TABLE monitors DROP INDEX monitors_url_active_unique');

        DB::statement('ALTER TABLE organizations ADD UNIQUE INDEX organizations_slug_unique (slug)');
        DB::statement('ALTER TABLE organizations DROP INDEX organizations_slug_active_unique');
    }
};
