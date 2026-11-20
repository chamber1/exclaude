<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local development login: test@test.com / password.
 *
 * Idempotent: re-running restores the password and email verification.
 * Refuses to run outside the local and testing environments.
 */
class TestUserSeeder extends Seeder
{
    public const EMAIL = 'test@test.com';

    public const PASSWORD = 'password';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('TestUserSeeder skipped: only runs in local/testing.');

            return;
        }

        $user = User::firstOrNew(['email' => self::EMAIL]);

        $user->forceFill([
            'name' => $user->name ?: 'Test User',
            'password' => self::PASSWORD,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $this->command?->info('Test user ready: '.self::EMAIL);
    }
}
