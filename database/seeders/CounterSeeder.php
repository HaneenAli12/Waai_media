<?php

namespace Database\Seeders;

use App\Models\Counter;
use Illuminate\Database\Seeder;

class CounterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Idempotently seeds the 5 fixed system counters.
     * Uses firstOrCreate so that existing, administrator-edited counter values
     * are preserved and never overwritten upon repeated initialization.
     */
    public function run(): void
    {
        foreach (Counter::FIXED_COUNTERS as $counterData) {
            Counter::firstOrCreate(
                ['key' => $counterData['key']],
                [
                    'name' => $counterData['name'],
                    'value' => $counterData['value'],
                    'order' => $counterData['order'],
                ]
            );
        }
    }
}
