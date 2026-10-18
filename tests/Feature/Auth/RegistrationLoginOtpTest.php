<?php

namespace Tests\Feature\Auth;

use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationLoginOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (
            getenv('DB_CONNECTION') !== 'sqlite'
            || getenv('DB_DATABASE') !== ':memory:'
        ) {
            throw new \RuntimeException(
                'Use SQLite :memory: for these tests.'
            );
        }

        parent::setUp();

        Mail::fake();
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'password' => Hash::make('TestPassword731!'),
            'is_active' => true,
            'email_verified_at' => null,
        ], $attributes));
    }

    private function code(User $user, array $attributes = []): OtpCode
    {
        return OtpCode::create(array_merge([
            'user_id' => $user->id,
            'type' => 'email_verification',
            'code' => Hash::make('123456'),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
        ], $attributes));
    }

    public function test_registration_hashes_password_and_sends_verification(): void
    {
        $payload = [
            'name' => 'Registration Test',
            'email' => 'registration731@example.com',
            'password' => 'TestPassword731!',
            'password_confirmation' => 'TestPassword731!',
        ];

        $this->postJson('/api/register', $payload)
            ->assertCreated()
            ->assertJsonMissingPath('token');

        $user = User::where('email', $payload['email'])->sole();

        $this->assertNull($user->email_verified_at);
        $this->assertSame('user', $user->role);
        $this->assertTrue(
            Hash::check($payload['password'], $user->password)
        );
        $this->assertSame(0, $user->tokens()->count());

        Mail::assertSent(
            OtpMail::class,
            function (OtpMail $mail) use ($user) {
                $stored = OtpCode::where('user_id', $user->id)
                    ->where('type', 'email_verification')
                    ->sole();

                $this->assertNotSame($mail->code, $stored->code);
                $this->assertTrue(
                    Hash::check($mail->code, $stored->code)
                );

                return $mail->hasTo($user->email)
                    && $mail->type === 'email_verification';
            },
        );

        $this->postJson('/api/register', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_valid_otp_verifies_email_and_cannot_be_reused(): void
    {
        $user = $this->user();
        $otp = $this->code($user);

        $payload = [
            'email' => $user->email,
            'code' => '123456',
        ];

        $this->postJson('/api/email/verify', $payload)
            ->assertOk()
            ->assertJsonStructure(['user', 'token']);

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNotNull($otp->fresh()->used_at);
        $this->assertSame(1, $user->tokens()->count());

        $this->postJson('/api/email/verify', $payload)
            ->assertUnprocessable();

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_wrong_and_expired_codes_are_rejected(): void
    {
        $user = $this->user();
        $otp = $this->code($user);

        $this->postJson('/api/email/verify', [
            'email' => $user->email,
            'code' => '654321',
        ])->assertUnprocessable();

        $this->assertSame(1, (int) $otp->fresh()->attempts);

        $otp->update([
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/email/verify', [
            'email' => $user->email,
            'code' => '123456',
        ])->assertUnprocessable();

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_login_rejects_wrong_password_and_ineligible_accounts(): void
    {
        $user = $this->user();

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'IncorrectPassword731!',
        ])->assertUnprocessable();

        $payload = [
            'email' => $user->email,
            'password' => 'TestPassword731!',
        ];

        $this->postJson('/api/login', $payload)
            ->assertForbidden()
            ->assertJsonPath('email_not_verified', true);

        $user->forceFill([
            'email_verified_at' => now(),
            'is_active' => false,
        ])->save();

        $this->postJson('/api/login', $payload)
            ->assertForbidden();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_verified_login_and_logout_revoke_only_current_token(): void
    {
        $user = $this->user([
            'email_verified_at' => now(),
        ]);

        $otherToken = $user->createToken('other-device');

        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'TestPassword731!',
        ])
            ->assertOk()
            ->assertJsonMissingPath('user.password')
            ->assertJsonStructure(['user', 'token']);

        $this->assertSame(2, $user->tokens()->count());

        $this->withToken($login->json('token'))
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertSame(1, $user->tokens()->count());

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $otherToken->accessToken->id,
        ]);
    }

    public function test_resend_replaces_old_code_and_enforces_cooldown(): void
    {
        $user = $this->user();
        $old = $this->code($user);

        // created_at is not fillable on OtpCode.
        // Set the fixture timestamp explicitly before testing resend.
        $old->forceFill([
            'created_at' => now()->subMinutes(2),
        ])->save();

        $this->assertTrue(
            $old->fresh()->created_at->lt(now()->subMinute())
        );

        $payload = [
            'email' => $user->email,
        ];

        $this->postJson('/api/email/resend-otp', $payload)
            ->assertOk();

        Mail::assertSent(OtpMail::class, 1);

        $this->assertDatabaseMissing('otp_codes', [
            'id' => $old->id,
        ]);

        $this->assertSame(
            1,
            OtpCode::where('user_id', $user->id)
                ->where('type', 'email_verification')
                ->whereNull('used_at')
                ->count(),
        );

        $this->postJson('/api/email/resend-otp', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        Mail::assertSent(OtpMail::class, 1);
    }
}