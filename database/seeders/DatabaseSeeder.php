<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Note: In accordance with architectural safety rules, no real email addresses are invented here.
     * Initial credentials can be seeded via INITIAL_ADMIN_EMAIL & INITIAL_ADMIN_PASSWORD environment variables,
     * or created when explicitly specified by the administrator.
     */
    public function run(): void
    {
        $initialAdminEmail = env('INITIAL_ADMIN_EMAIL');
        $initialAdminPassword = env('INITIAL_ADMIN_PASSWORD');

        if ($initialAdminEmail && $initialAdminPassword) {
            User::firstOrCreate(
                ['email' => $initialAdminEmail],
                [
                    'name' => 'Initial Administrator',
                    'password' => $initialAdminPassword,
                    'role' => 'admin',
                ]
            );
        }

        $this->call(CounterSeeder::class);
        $this->call(CategorySeeder::class);
    }
}
