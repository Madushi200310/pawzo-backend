<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_change_password(): void
    {
        $user = User::factory()->create([
            'password' => 'OldPass123',
        ]);

        // Create a real Sanctum token so currentAccessToken() works
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/password', [
                'current_password'      => 'OldPass123',
                'password'              => 'NewPass456',
                'password_confirmation' => 'NewPass456',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Password changed successfully. Other devices have been logged out.');

        // Confirm new password actually stored
        $this->assertTrue(Hash::check('NewPass456', $user->fresh()->password));
    }

    public function test_other_tokens_are_revoked_after_password_change(): void
    {
        $user = User::factory()->create([
            'password' => 'OldPass123',
        ]);

        // Create 3 tokens (simulate 3 devices)
        $tokenA = $user->createToken('device-A')->plainTextToken;
        $user->createToken('device-B');
        $user->createToken('device-C');

        $this->assertEquals(3, $user->tokens()->count());

        // Use device A to change password
        $this->withHeader('Authorization', "Bearer {$tokenA}")
            ->putJson('/api/password', [
                'current_password'      => 'OldPass123',
                'password'              => 'NewPass456',
                'password_confirmation' => 'NewPass456',
            ])
            ->assertOk();

        // Only the token used for this request should remain
        $this->assertEquals(1, $user->tokens()->count());
    }

    public function test_wrong_current_password_fails(): void
    {
        $user = User::factory()->create([
            'password' => 'OldPass123',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/password', [
                'current_password'      => 'WrongPass999',
                'password'              => 'NewPass456',
                'password_confirmation' => 'NewPass456',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);

        // Old password must still work
        $this->assertTrue(Hash::check('OldPass123', $user->fresh()->password));
    }

    public function test_new_password_must_be_different(): void
    {
        $user = User::factory()->create([
            'password' => 'SamePass123',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/password', [
                'current_password'      => 'SamePass123',
                'password'              => 'SamePass123',
                'password_confirmation' => 'SamePass123',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_new_password_must_be_confirmed(): void
    {
        $user = User::factory()->create([
            'password' => 'OldPass123',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/password', [
                'current_password'      => 'OldPass123',
                'password'              => 'NewPass456',
                'password_confirmation' => 'DifferentConfirmation',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_new_password_must_meet_strength_rules(): void
    {
        $user = User::factory()->create([
            'password' => 'OldPass123',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/password', [
                'current_password'      => 'OldPass123',
                'password'              => 'abc',
                'password_confirmation' => 'abc',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_guest_cannot_change_password(): void
    {
        $response = $this->putJson('/api/password', [
            'current_password'      => 'anything',
            'password'              => 'NewPass456',
            'password_confirmation' => 'NewPass456',
        ]);

        $response->assertUnauthorized();
    }
}