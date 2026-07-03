<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $user = User::withTrashed()->updateOrCreate([
            'email' => config('constants.default.user.email'),
        ], [
            'name' => config('constants.default.user.email'),
            'password' => Hash::make(config('constants.default.user.password')),
        ]);

        if ($user->trashed()) {
            $user->restore();
        }

        // Bootstrap: the default user is the platform super-admin, so a fresh
        // `migrate --seed` install is immediately usable — they can onboard the
        // first organization at /organizations (self-registration is disabled).
        // (is_super_admin is intentionally not mass-assignable.)
        $user->forceFill(['is_super_admin' => true])->save();
    }
}
