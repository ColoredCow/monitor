<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $hasData = DB::table('users')->exists()
            || DB::table('monitors')->exists()
            || DB::table('groups')->exists();

        if (! $hasData) {
            return; // fresh/test database — nothing to migrate
        }

        // Use the query builder, NOT Eloquent: these models gain SoftDeletes
        // later in the migration sequence, and their SoftDeletingScope would
        // reference a deleted_at column that does not exist yet at this point.
        $organizationId = DB::table('organizations')->where('slug', 'coloredcow')->value('id');

        if (! $organizationId) {
            $organizationId = DB::table('organizations')->insertGetId([
                'name' => 'ColoredCow',
                'slug' => 'coloredcow',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('groups')->whereNull('organization_id')->update(['organization_id' => $organizationId]);
        DB::table('monitors')->whereNull('organization_id')->update(['organization_id' => $organizationId]);

        $memberships = DB::table('users')->pluck('id')->map(fn ($userId) => [
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'role' => 'admin', // Organization::ROLE_ADMIN
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if ($memberships !== []) {
            // insertOrIgnore respects the unique(organization_id, user_id) index.
            DB::table('organization_user')->insertOrIgnore($memberships);
        }

        $defaultEmail = config('constants.default.user.email');
        if ($defaultEmail) {
            DB::table('users')->where('email', $defaultEmail)->update(['is_super_admin' => true]);
        }
    }

    public function down(): void
    {
        // Non-reversible data backfill.
    }
};
