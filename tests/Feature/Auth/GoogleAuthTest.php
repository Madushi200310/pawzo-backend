<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogleUser(array $overrides = []): SocialiteUser
    {
        $user = Mockery::mock(SocialiteUser::class);
        $user->shouldReceive('getId')->andReturn($overrides['id'] ?? 'google-123456');
        $user->shouldReceive('getName')->andReturn($overrides['name'] ?? 'Madushi Google');
        $user->shouldReceive('getEmail')->andReturn($overrides['email'] ?? 'madushi.google@example.com');
        $user->shouldReceive('getAvatar')->andReturn($overrides['avatar'] ?? 'https://example.com/avatar.jpg');
        return $user;
    }

    public function test_redirect_returns_google_url(): void
    {
        $response = $this->getJson('/api/auth/google/redirect');

        $response->assertOk()
            ->assertJsonStructure(['message', 'redirect_url']);

        $this->assertStringContainsString('accounts.google.com', $response->json('redirect_url'));
    }

    public function test_callback_creates_new_user(): void
    {
        Socialite::shouldReceive('driver->stateless->user')
            ->andReturn($this->fakeGoogleUser());

        $response = $this->getJson('/api/auth/google/callback');

        $response->assertOk()
            ->assertJsonPath('message', 'Login via Google successful.')
            ->assertJsonStructure(['user', 'token']);

        $this->assertDatabaseHas('users', [
            'email'     => 'madushi.google@example.com',
            'google_id' => 'google-123456',
        ]);
    }

    public function test_callback_logs_in_existing_user_by_email(): void
    {
        $existing = User::factory()->create([
            'email'     => 'madushi.google@example.com',
            'google_id' => null,
        ]);

        Socialite::shouldReceive('driver->stateless->user')
            ->andReturn($this->fakeGoogleUser());

        $this->getJson('/api/auth/google/callback')->assertOk();

        // The existing user should now be linked to the Google account
        $this->assertDatabaseHas('users', [
            'id'        => $existing->id,
            'google_id' => 'google-123456',
        ]);
    }

    public function test_callback_logs_in_existing_user_by_google_id(): void
    {
        $existing = User::factory()->create([
            'email'     => 'old@example.com',
            'google_id' => 'google-123456',
        ]);

        Socialite::shouldReceive('driver->stateless->user')
            ->andReturn($this->fakeGoogleUser([
                'email' => 'different@example.com', // same google_id, different email
            ]));

        $response = $this->getJson('/api/auth/google/callback');

        $response->assertOk()
            ->assertJsonPath('user.id', $existing->id);
    }

    public function test_callback_blocks_deactivated_user(): void
    {
        User::factory()->create([
            'email'     => 'madushi.google@example.com',
            'is_active' => false,
        ]);

        Socialite::shouldReceive('driver->stateless->user')
            ->andReturn($this->fakeGoogleUser());

        $this->getJson('/api/auth/google/callback')
            ->assertForbidden();
    }

    public function test_callback_returns_401_on_socialite_failure(): void
    {
        Socialite::shouldReceive('driver->stateless->user')
            ->andThrow(new \Exception('Google said no'));

        $this->getJson('/api/auth/google/callback')
            ->assertUnauthorized();
    }
}