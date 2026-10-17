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

    protected function setUp(): void
    {
        if (
            getenv('DB_CONNECTION') !== 'sqlite'
            || getenv('DB_DATABASE') !== ':memory:'
        ) {
            throw new \RuntimeException('Use SQLite :memory: for these tests.');
        }

        parent::setUp();

        config([
            'services.facebook.client_id' => 'test-facebook-client',
            'services.facebook.client_secret' => 'test-facebook-secret',
            'services.facebook.redirect' =>
                'http://localhost:8000/api/auth/facebook/callback',
        ]);
    }

    private function fakeFacebookUser(array $overrides = []): SocialiteUser
    {
        $data = array_replace([
            'id' => 'fb-999888',
            'name' => 'Madushi FB',
            'email' => 'madushi.fb@example.com',
            'avatar' => 'https://example.com/avatar.jpg',
        ], $overrides);

        $user = Mockery::mock(SocialiteUser::class);
        $user->shouldReceive('getId')->andReturn($data['id']);
        $user->shouldReceive('getName')->andReturn($data['name']);
        $user->shouldReceive('getEmail')->andReturn($data['email']);
        $user->shouldReceive('getAvatar')->andReturn($data['avatar']);

        return $user;
    }

    private function mockCallback(array $overrides = []): void
    {
        Socialite::shouldReceive('driver->stateless->user')
            ->andReturn($this->fakeFacebookUser($overrides));
    }

    public function test_redirect_returns_facebook_url(): void
    {
        $response = $this->getJson('/api/auth/facebook/redirect')
            ->assertOk()
            ->assertJsonStructure(['message', 'redirect_url']);

        $this->assertStringContainsString(
            'facebook.com',
            $response->json('redirect_url'),
        );
    }

    public function test_callback_creates_verified_new_user(): void
    {
        $this->mockCallback();

        $response = $this->getJson('/api/auth/facebook/callback')
            ->assertOk()
            ->assertJsonPath('message', 'Login via Facebook successful.')
            ->assertJsonStructure(['user', 'token']);

        $user = User::where('facebook_id', 'fb-999888')->sole();

        $this->assertSame('madushi.fb@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame($user->id, $response->json('user.id'));
    }

    public function test_existing_email_is_not_automatically_linked(): void
    {
        $existing = User::factory()->create([
            'email' => 'madushi.fb@example.com',
            'facebook_id' => null,
            'is_active' => true,
            'email_verified_at' => null,
        ]);

        $passwordBefore = $existing->password;

        $this->mockCallback();

        $this->getJson('/api/auth/facebook/callback')
            ->assertStatus(409)
            ->assertJsonPath('error', 'account_link_required')
            ->assertJsonMissingPath('token');

        $this->assertNull($existing->fresh()->facebook_id);
        $this->assertNull($existing->fresh()->email_verified_at);
        $this->assertSame($passwordBefore, $existing->fresh()->password);
        $this->assertSame(0, $existing->tokens()->count());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_callback_logs_in_existing_user_by_facebook_id(): void
    {
        $existing = User::factory()->create([
            'email' => 'old@example.com',
            'facebook_id' => 'fb-999888',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->mockCallback(['email' => 'different@example.com']);

        $this->getJson('/api/auth/facebook/callback')
            ->assertOk()
            ->assertJsonPath('user.id', $existing->id);

        $this->assertSame('old@example.com', $existing->fresh()->email);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_callback_blocks_deactivated_user(): void
    {
        $existing = User::factory()->create([
            'email' => 'madushi.fb@example.com',
            'facebook_id' => null,
            'is_active' => false,
        ]);

        $this->mockCallback();

        $this->getJson('/api/auth/facebook/callback')->assertForbidden();

        $this->assertNull($existing->fresh()->facebook_id);
        $this->assertSame(0, $existing->tokens()->count());
    }

    public function test_callback_blocks_deleted_provider_account(): void
    {
        $existing = User::factory()->create([
            'email' => 'madushi.fb@example.com',
            'facebook_id' => 'fb-999888',
            'is_active' => true,
        ]);

        $existing->delete();
        $this->mockCallback();

        $this->getJson('/api/auth/facebook/callback')->assertForbidden();

        $this->assertSame(0, $existing->tokens()->count());
        $this->assertSame(1, User::withTrashed()->count());
    }

    public function test_missing_email_does_not_create_user(): void
    {
        $this->mockCallback(['email' => null]);

        $this->getJson('/api/auth/facebook/callback')
            ->assertUnprocessable();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_callback_returns_401_without_exposing_provider_error(): void
    {
        Socialite::shouldReceive('driver->stateless->user')
            ->andThrow(new \Exception('Private provider error details'));

        $this->getJson('/api/auth/facebook/callback')
            ->assertUnauthorized()
            ->assertJsonMissingPath('error')
            ->assertJsonMissingPath('token');
    }
}