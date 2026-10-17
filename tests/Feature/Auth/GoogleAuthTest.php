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
            'services.google.client_id' => 'test-google-client',
            'services.google.client_secret' => 'test-google-secret',
            'services.google.redirect' =>
                'http://localhost:8000/api/auth/google/callback',
        ]);
    }

    private function fakeGoogleUser(array $overrides = []): SocialiteUser
    {
        $data = array_replace([
            'id' => 'google-123456',
            'name' => 'Madushi Google',
            'email' => 'madushi.google@example.com',
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
            ->andReturn($this->fakeGoogleUser($overrides));
    }

    public function test_redirect_returns_google_url(): void
    {
        $response = $this->getJson('/api/auth/google/redirect')
            ->assertOk()
            ->assertJsonStructure(['message', 'redirect_url']);

        $this->assertStringContainsString(
            'accounts.google.com',
            $response->json('redirect_url'),
        );
    }

    public function test_callback_creates_verified_new_user(): void
    {
        $this->mockCallback();

        $response = $this->getJson('/api/auth/google/callback')
            ->assertOk()
            ->assertJsonPath('message', 'Login via Google successful.')
            ->assertJsonStructure(['user', 'token']);

        $user = User::where('google_id', 'google-123456')->sole();

        $this->assertSame('madushi.google@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame($user->id, $response->json('user.id'));
    }

    public function test_existing_email_is_not_automatically_linked(): void
    {
        $existing = User::factory()->create([
            'email' => 'madushi.google@example.com',
            'google_id' => null,
            'is_active' => true,
            'email_verified_at' => null,
        ]);

        $passwordBefore = $existing->password;

        $this->mockCallback();

        $this->getJson('/api/auth/google/callback')
            ->assertStatus(409)
            ->assertJsonPath('error', 'account_link_required')
            ->assertJsonMissingPath('token');

        $this->assertNull($existing->fresh()->google_id);
        $this->assertNull($existing->fresh()->email_verified_at);
        $this->assertSame($passwordBefore, $existing->fresh()->password);
        $this->assertSame(0, $existing->tokens()->count());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_callback_logs_in_existing_user_by_google_id(): void
    {
        $existing = User::factory()->create([
            'email' => 'old@example.com',
            'google_id' => 'google-123456',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->mockCallback(['email' => 'different@example.com']);

        $this->getJson('/api/auth/google/callback')
            ->assertOk()
            ->assertJsonPath('user.id', $existing->id);

        $this->assertSame('old@example.com', $existing->fresh()->email);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_callback_blocks_deactivated_user(): void
    {
        $existing = User::factory()->create([
            'email' => 'madushi.google@example.com',
            'google_id' => null,
            'is_active' => false,
        ]);

        $this->mockCallback();

        $this->getJson('/api/auth/google/callback')->assertForbidden();

        $this->assertNull($existing->fresh()->google_id);
        $this->assertSame(0, $existing->tokens()->count());
    }

    public function test_callback_blocks_deleted_provider_account(): void
    {
        $existing = User::factory()->create([
            'email' => 'madushi.google@example.com',
            'google_id' => 'google-123456',
            'is_active' => true,
        ]);

        $existing->delete();
        $this->mockCallback();

        $this->getJson('/api/auth/google/callback')->assertForbidden();

        $this->assertSame(0, $existing->tokens()->count());
        $this->assertSame(1, User::withTrashed()->count());
    }

    public function test_missing_email_does_not_create_user(): void
    {
        $this->mockCallback(['email' => null]);

        $this->getJson('/api/auth/google/callback')
            ->assertUnprocessable();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_callback_returns_401_without_exposing_provider_error(): void
    {
        Socialite::shouldReceive('driver->stateless->user')
            ->andThrow(new \Exception('Private provider error details'));

        $this->getJson('/api/auth/google/callback')
            ->assertUnauthorized()
            ->assertJsonMissingPath('error')
            ->assertJsonMissingPath('token');
    }
}