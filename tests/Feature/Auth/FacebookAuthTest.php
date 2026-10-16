<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class FacebookAuthTest extends TestCase
{
    use RefreshDatabase;

    private function fakeFacebookUser(array $overrides = []): SocialiteUser
    {
        $user = Mockery::mock(SocialiteUser::class);
        $user->shouldReceive('getId')->andReturn($overrides['id'] ?? 'fb-999888');
        $user->shouldReceive('getName')->andReturn($overrides['name'] ?? 'Madushi FB');
        $user->shouldReceive('getEmail')->andReturn($overrides['email'] ?? 'madushi.fb@example.com');
        $user->shouldReceive('getAvatar')->andReturn($overrides['avatar'] ?? 'https://fb.example.com/avatar.jpg');
        return $user;
    }

    public function test_redirect_returns_facebook_url(): void
    {
        $response = $this->getJson('/api/auth/facebook/redirect');

        $response->assertOk()
            ->assertJsonStructure(['message', 'redirect_url']);

        $this->assertStringContainsString('facebook.com', $response->json('redirect_url'));
    }

    public function test_callback_creates_new_user(): void
    {
        Socialite::shouldReceive('driver->stateless->user')
            ->andReturn($this->fakeFacebookUser());

        $response = $this->getJson('/api/auth/facebook/callback');

        $response->assertOk()
            ->assertJsonPath('message', 'Login via Facebook successful.')
            ->assertJsonStructure(['user', 'token']);

        $this->assertDatabaseHas('users', [
            'email'       => 'madushi.fb@example.com',
            'facebook_id' => 'fb-999888',
        ]);
    }

    public function test_callback_logs_in_existing_user_by_email(): void
    {
        $existing = User::factory()->create([
            'email'       => 'madushi.fb@example.com',
            'facebook_id' => null,
        ]);

        Socialite::shouldReceive('driver->stateless->user')
            ->andReturn($this->fakeFacebookUser());

        $this->getJson('/api/auth/facebook/callback')->assertOk();

        $this->assertDatabaseHas('users', [
            'id'          => $existing->id,
            'facebook_id' => 'fb-999888',
        ]);
    }

    public function test_callback_logs_in_existing_user_by_facebook_id(): void
    {
        $existing = User::factory()->create([
            'email'       => 'old@example.com',
            'facebook_id' => 'fb-999888',
        ]);

        Socialite::shouldReceive('driver->stateless->user')
            ->andReturn($this->fakeFacebookUser([
                'email' => 'different@example.com',
            ]));

        $response = $this->getJson('/api/auth/facebook/callback');

        $response->assertOk()
            ->assertJsonPath('user.id', $existing->id);
    }

    public function test_callback_blocks_deactivated_user(): void
    {
        User::factory()->create([
            'email'       => 'madushi.fb@example.com',
            'is_active'   => false,
        ]);

        Socialite::shouldReceive('driver->stateless->user')
            ->andReturn($this->fakeFacebookUser());

        $this->getJson('/api/auth/facebook/callback')
            ->assertForbidden();
    }

    public function test_callback_returns_401_on_socialite_failure(): void
    {
        Socialite::shouldReceive('driver->stateless->user')
            ->andThrow(new \Exception('Facebook said no'));

        $this->getJson('/api/auth/facebook/callback')
            ->assertUnauthorized();
    }
}