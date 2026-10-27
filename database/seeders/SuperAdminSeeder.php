<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::withTrashed()->where('email', 'waaimedia@gmail.com')->first();

        if ($user) {
            if ($user->trashed()) {
                $user->restore();
            }

            $user->update([
                'name' => 'Super Admin',
                'password' => Hash::make('Waaimedia@@2026'),
                'role' => 'super_admin',
            ]);

            $this->command->info("Super Admin updated (ID: {$user->id}).");
        } else {
            $user = User::create([
                'name' => 'Super Admin',
                'email' => 'waaimedia@gmail.com',
                'password' => Hash::make('Waaimedia@@2026'),
                'role' => 'super_admin',
            ]);

            $this->command->info("Super Admin created (ID: {$user->id}).");
        }
    }
}
