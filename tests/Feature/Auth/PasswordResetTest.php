<?php

namespace Tests\Feature\Auth;

use App\Mail\OtpMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        if (
            getenv('DB_CONNECTION') !== 'sqlite'
            || getenv('DB_DATABASE') !== ':memory:'
        ) {
            throw new \RuntimeException(
                'Use SQLite :memory: for password reset tests.'
            );
        }

        if (is_file(__DIR__.'/../../../bootstrap/cache/config.php')) {
            throw new \RuntimeException(
                'Run php artisan config:clear before testing.'
            );
        }

        parent::setUp();

        Mail::fake();

        $this->user = User::factory()->create([
            'email' => 'reset-test@example.com',
            'password' => Hash::make('OldPassword123'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function makeCode(
        string $type = 'password_reset',
        string $code = '123456',
    ): OtpCode {
        return OtpCode::create([
            'user_id' => $this->user->id,
            'type' => $type,
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    private function resetPayload(array $overrides = []): array
    {
        return array_replace([
            'email' => $this->user->email,
            'code' => '123456',
            'password' => 'NewPassword456',
            'password_confirmation' => 'NewPassword456',
        ], $overrides);
    }

    public function test_forgot_sends_hashed_reset_code_by_email(): void
    {
        $this->postJson('/api/forgot-password', [
            'email' => $this->user->email,
        ])->assertOk();

        $stored = OtpCode::where('user_id', $this->user->id)
            ->where('type', 'password_reset')
            ->sole();

        Mail::assertSent(OtpMail::class, function (OtpMail $mail) use (
            $stored,
        ) {
            return $mail->hasTo($this->user->email)
                && $mail->type === 'password_reset'
                && preg_match('/^\d{6}$/', $mail->code) === 1
                && Hash::check($mail->code, $stored->code);
        });

        $this->assertNull($stored->used_at);
        $this->assertTrue($stored->expires_at->isFuture());
    }

    public function test_unknown_email_has_same_response_without_email(): void
    {
        $known = $this->postJson('/api/forgot-password', [
            'email' => $this->user->email,
        ])->assertOk()->json();

        $unknown = $this->postJson('/api/forgot-password', [
            'email' => 'missing@example.com',
        ])->assertOk()->json();

        $this->assertSame($known, $unknown);
        Mail::assertSent(OtpMail::class, 1);
    }

    public function test_cooldown_does_not_send_duplicate_email(): void
    {
        $payload = ['email' => $this->user->email];

        $first = $this->postJson('/api/forgot-password', $payload)
            ->assertOk()->json();

        $second = $this->postJson('/api/forgot-password', $payload)
            ->assertOk()->json();

        $this->assertSame($first, $second);
        Mail::assertSent(OtpMail::class, 1);
        $this->assertDatabaseCount('otp_codes', 1);
    }

    public function test_valid_code_changes_password_and_revokes_tokens(): void
    {
        $code = $this->makeCode();
        $this->user->createToken('existing-session');

        $this->postJson('/api/reset-password', $this->resetPayload())
            ->assertOk();

        $fresh = $this->user->fresh();

        $this->assertTrue(
            Hash::check('NewPassword456', $fresh->password)
        );

        $this->assertFalse(
            Hash::check('OldPassword123', $fresh->password)
        );

        $this->assertNotNull($code->fresh()->used_at);
        $this->assertSame(0, $fresh->tokens()->count());

        $this->postJson('/api/login', [
            'email' => $fresh->email,
            'password' => 'NewPassword456',
        ])->assertOk()
            ->assertJsonStructure(['token']);

        $this->postJson('/api/login', [
            'email' => $fresh->email,
            'password' => 'OldPassword123',
        ])->assertUnprocessable();
    }

    public function test_reset_code_cannot_be_reused(): void
    {
        $this->makeCode();

        $this->postJson('/api/reset-password', $this->resetPayload())
            ->assertOk();

        $this->postJson('/api/reset-password', $this->resetPayload([
            'password' => 'AnotherPassword789',
            'password_confirmation' => 'AnotherPassword789',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertTrue(
            Hash::check(
                'NewPassword456',
                $this->user->fresh()->password,
            )
        );
    }

    public function test_invalid_code_preserves_password_and_counts_attempt(): void
    {
        $code = $this->makeCode();
        $this->user->createToken('existing-session');

        $this->postJson('/api/reset-password', $this->resetPayload([
            'code' => '654321',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertSame(1, (int) $code->fresh()->attempts);
        $this->assertNull($code->fresh()->used_at);

        $this->assertTrue(
            Hash::check(
                'OldPassword123',
                $this->user->fresh()->password,
            )
        );

        $this->assertSame(1, $this->user->tokens()->count());
    }

    public function test_expired_code_is_rejected(): void
    {
        $code = $this->makeCode();

        $code->update([
            'expires_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/reset-password', $this->resetPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertNull($code->fresh()->used_at);
    }

    public function test_exhausted_code_is_rejected_even_when_correct(): void
    {
        $code = $this->makeCode();
        $code->update(['attempts' => 5]);

        $this->postJson('/api/reset-password', $this->resetPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertNull($code->fresh()->used_at);
    }

    public function test_email_verification_code_cannot_reset_password(): void
    {
        $code = $this->makeCode('email_verification');

        $this->postJson('/api/reset-password', $this->resetPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertNull($code->fresh()->used_at);
    }

    public function test_password_confirmation_is_required_before_using_code(): void
    {
        $code = $this->makeCode();

        $this->postJson('/api/reset-password', $this->resetPayload([
            'password_confirmation' => 'DifferentPassword789',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertNull($code->fresh()->used_at);

        $this->postJson('/api/reset-password', $this->resetPayload())
            ->assertOk();
    }

    public function test_inactive_account_cannot_request_or_reset_password(): void
    {
        $code = $this->makeCode();
        $this->user->update(['is_active' => false]);

        $this->postJson('/api/forgot-password', [
            'email' => $this->user->email,
        ])->assertOk();

        Mail::assertNothingSent();

        $this->postJson('/api/reset-password', $this->resetPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->assertNull($code->fresh()->used_at);
    }
}