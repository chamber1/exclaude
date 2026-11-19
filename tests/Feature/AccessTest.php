<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_parser_is_open_to_guests_and_shows_guest_limits(): void
    {
        $this->get('/parser')
            ->assertOk()
            ->assertSee('Без входа: качество до 720p', false)
            ->assertDontSee('name="rights"', false);
    }

    public function test_parser_for_users_has_no_guest_notice(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/parser')
            ->assertOk()
            ->assertDontSee('Без входа');
    }

    public function test_webcam_requires_login(): void
    {
        $this->get('/webcam')->assertRedirect('/login');
        $this->getJson('/webcam/snapshots/1')->assertUnauthorized();
    }

    public function test_webcam_is_available_after_login(): void
    {
        $this->actingAs(User::factory()->create())->get('/webcam')->assertOk();
    }

    public function test_login_works_and_wrong_password_fails(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/home');
        $this->assertAuthenticatedAs($user);
    }

    public function test_old_synchronous_parser_endpoint_is_gone(): void
    {
        $this->post('/parser', ['url' => 'https://youtu.be/jNQXAC9IVRw'])->assertStatus(405);
    }

    public function test_health_endpoint(): void
    {
        $this->getJson('/up')->assertOk()->assertJsonPath('status', 'ok');
    }
}
