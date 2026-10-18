<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

abstract class SocialAuthTestCase extends TestCase
{
    use RefreshDatabase;

    protected const PROVIDER = '';

    protected function setUp(): void
    {
        if (
            getenv('DB_CONNECTION') !== 'sqlite'
            || getenv('DB_DATABASE') !== ':memory:'
        ) {
            throw new \RuntimeException(
                'Use SQLite :memory: for social authentication tests.'
            );
        }

        // Filesystem path works before Laravel is bootstrapped.
        if (is_file(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) {
            throw new \RuntimeException(
                'Run php artisan config:clear before testing.'
            );
        }

        parent::setUp();

        $provider = static::PROVIDER;

        config([
            'session.driver' => 'array',
            "services.{$provider}.client_id" => 'test-client',
            "services.{$provider}.client_secret" => 'test-secret',
            "services.{$provider}.redirect" =>
                "http://localhost:8000/api/auth/{$provider}/callback",
        ]);
    }

    private function column(): string
    {
        return static::PROVIDER.'_id';
    }

    private function email(): string
    {
        return static::PROVIDER.'.test@example.com';
    }

    private function callbackUrl(string $state = 'valid-test-state'): string
    {
        return '/api/auth/'.static::PROVIDER.'/callback?'
            .http_build_query(['state' => $state]);
    }

    private function prepareState(
        string $state = 'valid-test-state',
        ?int $expiresAt = null,
        ?string $provider = null,
    ): void {
        $provider ??= static::PROVIDER;

        $this->withSession([
            'pawzo_oauth' => [
                $provider => [
                    'state' => $state,
                    'expires_at' => $expiresAt
                        ?? now()->addMinutes(10)->timestamp,
                ],
            ],
        ]);
    }

    private function mockProvider(array $overrides = []): void
    {
        $data = array_replace([
            'id' => 'provider-test-123',
            'name' => 'Social Test User',
            'email' => $this->email(),
            'avatar' => 'https://example.com/avatar.png',
        ], $overrides);

        $user = Mockery::mock(SocialiteUser::class);
        $user->shouldReceive('getId')->andReturn($data['id']);
        $user->shouldReceive('getName')->andReturn($data['name']);
        $user->shouldReceive('getEmail')->andReturn($data['email']);
        $user->shouldReceive('getAvatar')->andReturn($data['avatar']);

        Socialite::shouldReceive('driver->stateless->user')
            ->once()
            ->andReturn($user);
    }

    public function test_redirect_contains_session_bound_state(): void
    {
        $provider = static::PROVIDER;

        $response = $this->getJson("/api/auth/{$provider}/redirect")
            ->assertOk()
            ->assertJsonStructure(['message', 'redirect_url']);

        $url = $response->json('redirect_url');
        $query = [];

        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        $this->assertArrayHasKey('state', $query);
        $this->assertSame(64, strlen($query['state']));

        $response->assertSessionHas(
            "pawzo_oauth.{$provider}.state",
            $query['state'],
        );

        $expectedHost = $provider === 'google'
            ? 'accounts.google.com'
            : 'facebook.com';

        $this->assertStringContainsString(
            $expectedHost,
            (string) parse_url($url, PHP_URL_HOST),
        );
    }

    public function test_valid_callback_creates_verified_user_and_token(): void
    {
        $this->prepareState();
        $this->mockProvider();

        $response = $this->getJson($this->callbackUrl())
            ->assertOk()
            ->assertJsonStructure(['user', 'token']);

        $user = User::where($this->column(), 'provider-test-123')->sole();

        $this->assertSame($this->email(), $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame($user->id, $response->json('user.id'));
    }

    public function test_existing_email_does_not_auto_link_or_issue_token(): void
    {
        $existing = User::factory()->create([
            'email' => $this->email(),
            $this->column() => null,
            'is_active' => true,
            'email_verified_at' => null,
        ]);

        $password = $existing->password;

        $this->prepareState();
        $this->mockProvider();

        $this->getJson($this->callbackUrl())
            ->assertStatus(409)
            ->assertJsonPath('error', 'account_link_required')
            ->assertJsonMissingPath('token');

        $this->assertNull(
            $existing->fresh()->getAttribute($this->column())
        );
        $this->assertNull($existing->fresh()->email_verified_at);
        $this->assertSame($password, $existing->fresh()->password);
        $this->assertSame(0, $existing->tokens()->count());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_linked_provider_id_logs_in_without_changing_email(): void
    {
        $existing = User::factory()->create([
            'email' => 'original@example.com',
            $this->column() => 'provider-test-123',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->prepareState();
        $this->mockProvider(['email' => 'changed@example.com']);

        $this->getJson($this->callbackUrl())
            ->assertOk()
            ->assertJsonPath('user.id', $existing->id);

        $this->assertSame('original@example.com', $existing->fresh()->email);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_inactive_existing_account_is_blocked(): void
    {
        $existing = User::factory()->create([
            'email' => $this->email(),
            $this->column() => null,
            'is_active' => false,
        ]);

        $this->prepareState();
        $this->mockProvider();

        $this->getJson($this->callbackUrl())->assertForbidden();

        $this->assertNull(
            $existing->fresh()->getAttribute($this->column())
        );
        $this->assertSame(0, $existing->tokens()->count());
    }

    public function test_deleted_provider_account_is_blocked(): void
    {
        $existing = User::factory()->create([
            'email' => $this->email(),
            $this->column() => 'provider-test-123',
            'is_active' => true,
        ]);

        $existing->delete();

        $this->prepareState();
        $this->mockProvider();

        $this->getJson($this->callbackUrl())->assertForbidden();

        $this->assertSame(0, $existing->tokens()->count());
        $this->assertSame(1, User::withTrashed()->count());
    }

    public function test_missing_email_does_not_create_account(): void
    {
        $this->prepareState();
        $this->mockProvider(['email' => null]);

        $this->getJson($this->callbackUrl())->assertUnprocessable();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_provider_failure_does_not_expose_details(): void
    {
        $this->prepareState();

        Socialite::shouldReceive('driver->stateless->user')
            ->once()
            ->andThrow(new \Exception('Private provider details'));

        $response = $this->getJson($this->callbackUrl())
            ->assertUnauthorized()
            ->assertJsonMissingPath('error')
            ->assertJsonMissingPath('token');

        $this->assertStringNotContainsString(
            'Private provider details',
            $response->getContent(),
        );
    }

    public function test_missing_state_is_rejected_before_provider_call(): void
    {
        Socialite::shouldReceive('driver')->never();

        $this->getJson('/api/auth/'.static::PROVIDER.'/callback')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_oauth_state');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_wrong_state_is_rejected_before_provider_call(): void
    {
        $this->prepareState('expected-state');

        Socialite::shouldReceive('driver')->never();

        $this->getJson($this->callbackUrl('wrong-state'))
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_oauth_state');
    }

    public function test_expired_state_is_rejected_before_provider_call(): void
    {
        $this->prepareState(
            expiresAt: now()->subMinute()->timestamp,
        );

        Socialite::shouldReceive('driver')->never();

        $this->getJson($this->callbackUrl())
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_oauth_state');
    }

    public function test_state_cannot_be_reused(): void
    {
        $this->prepareState();
        $this->mockProvider();

        $this->getJson($this->callbackUrl())->assertOk();

        $this->getJson($this->callbackUrl())
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_oauth_state');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_state_for_another_provider_is_rejected(): void
    {
        $other = static::PROVIDER === 'google' ? 'facebook' : 'google';

        $this->prepareState(provider: $other);

        Socialite::shouldReceive('driver')->never();

        $this->getJson($this->callbackUrl())
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_oauth_state');
    }
}