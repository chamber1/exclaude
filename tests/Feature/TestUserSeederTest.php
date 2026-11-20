<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\TestUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class TestUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_verified_user_with_known_password_and_is_idempotent(): void
    {
        $this->seed(TestUserSeeder::class);
        $this->seed(TestUserSeeder::class);

        $this->assertSame(1, User::where('email', TestUserSeeder::EMAIL)->count());

        $user = User::where('email', TestUserSeeder::EMAIL)->first();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Auth::validate([
            'email' => TestUserSeeder::EMAIL,
            'password' => TestUserSeeder::PASSWORD,
        ]));
    }

    public function test_restores_changed_password(): void
    {
        $this->seed(TestUserSeeder::class);
        User::where('email', TestUserSeeder::EMAIL)->first()->update(['password' => 'something-else']);

        $this->seed(TestUserSeeder::class);

        $this->assertTrue(Auth::validate([
            'email' => TestUserSeeder::EMAIL,
            'password' => TestUserSeeder::PASSWORD,
        ]));
    }
}
