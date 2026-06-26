<?php

use App\Models\Organization;
use App\Models\User;
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

        $organization = Organization::firstOrCreate(
            ['slug' => 'coloredcow'],
            ['name' => 'ColoredCow']
        );

        DB::table('groups')->whereNull('organization_id')->update(['organization_id' => $organization->id]);
        DB::table('monitors')->whereNull('organization_id')->update(['organization_id' => $organization->id]);

        foreach (User::all() as $user) {
            $organization->users()->syncWithoutDetaching([
                $user->id => ['role' => Organization::ROLE_ADMIN],
            ]);
        }

        $defaultEmail = config('constants.default.user.email');
        if ($defaultEmail) {
            User::where('email', $defaultEmail)->update(['is_super_admin' => true]);
        }
    }

    public function down(): void
    {
        // Non-reversible data backfill.
    }
};
