<?php

namespace Database\Seeders;

use App\User;
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
        User::updateOrCreate([
            'email' => config('constants.default.user.email'),
        ], [
            'name' => config('constants.default.user.email'),
            'password' => Hash::make(config('constants.default.user.password')),
        ]);
    }
}
