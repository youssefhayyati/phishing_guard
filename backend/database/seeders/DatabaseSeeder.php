<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'demo@phishguard.test'],
            ['name' => 'Demo Trainer', 'password' => Hash::make('password')],
        );

        Campaign::seedSamplesFor($user);
    }
}
