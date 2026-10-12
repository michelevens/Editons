<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The demo account used while the product is under construction.
 *
 * Refuses to run outside local/testing. A seeder that creates a known
 * password is a backdoor anywhere else, and `db:seed` on a production box is
 * a mistake someone makes exactly once.
 *
 * updateOrCreate rather than create, so re-seeding is idempotent and does not
 * collide with the unique email index.
 */
class DemoSeeder extends Seeder
{
    public const EMAIL = 'test@editons.local';

    public const PASSWORD = 'password';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('DemoSeeder skipped: not a local environment.');

            return;
        }

        User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Test Author',
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
            ],
        );

        $this->command?->info('Demo account ready: '.self::EMAIL.' / '.self::PASSWORD);
    }
}
