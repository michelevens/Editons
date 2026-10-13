<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Only the demo account, which decides for itself whether it should exist
     * — see DemoSeeder. Laravel's stock `test@example.com` factory user is
     * gone: it was created unconditionally, so running this against any
     * deployed database made a real account with a random password and no
     * owner.
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
