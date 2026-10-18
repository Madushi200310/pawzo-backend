<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminProfileSecurityTest extends TestCase
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
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => Hash::make('OldPassword731!'),
        ]);
    }

    public function test_admin_can_view_and_update_own_profile(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('profile-test');

        $this->withToken($token->plainTextToken)
            ->getJson('/api/admin/profile')
            ->assertOk()
            ->assertJsonPath('user.id', $admin->id)
            ->assertJsonMissingPath('user.password');

        $this->withToken($token->plainTextToken)
            ->putJson('/api/admin/profile', [
                'name' => 'Updated Admin',
                'phone' => '0700000000',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Updated Admin')
            ->assertJsonPath('user.phone', '0700000000');

        $this->assertSame('Updated Admin', $admin->fresh()->name);
        $this->assertSame('admin', $admin->fresh()->role);
    }

    public function test_password_change_keeps_current_token_and_revokes_others(): void
    {
        $admin = $this->admin();
        $current = $admin->createToken('current-device');
        $other = $admin->createToken('other-device');

        $this->withToken($current->plainTextToken)
            ->postJson('/api/admin/profile/change-password', [
                'current_password' => 'OldPassword731!',
                'new_password' => 'NewPassword731!',
                'new_password_confirmation' => 'NewPassword731!',
            ])
            ->assertOk();

        $this->assertTrue(
            Hash::check('NewPassword731!', $admin->fresh()->password)
        );

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $current->accessToken->id,
        ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $other->accessToken->id,
        ]);

        $this->assertSame(1, $admin->tokens()->count());
    }

    public function test_wrong_current_password_cannot_change_password(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('security-test');

        $this->withToken($token->plainTextToken)
            ->postJson('/api/admin/profile/change-password', [
                'current_password' => 'WrongPassword731!',
                'new_password' => 'NewPassword731!',
                'new_password_confirmation' => 'NewPassword731!',
            ])
            ->assertUnprocessable();

        $this->assertTrue(
            Hash::check('OldPassword731!', $admin->fresh()->password)
        );

        $this->assertSame(1, $admin->tokens()->count());
    }

    public function test_password_reuse_and_confirmation_mismatch_are_rejected(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('security-test');

        $this->withToken($token->plainTextToken)
            ->postJson('/api/admin/profile/change-password', [
                'current_password' => 'OldPassword731!',
                'new_password' => 'OldPassword731!',
                'new_password_confirmation' => 'OldPassword731!',
            ])
            ->assertUnprocessable();

        $this->withToken($token->plainTextToken)
            ->postJson('/api/admin/profile/change-password', [
                'current_password' => 'OldPassword731!',
                'new_password' => 'NewPassword731!',
                'new_password_confirmation' => 'DifferentPassword731!',
            ])
            ->assertUnprocessable();

        $this->assertTrue(
            Hash::check('OldPassword731!', $admin->fresh()->password)
        );
    }

    public function test_logout_all_revokes_every_token(): void
    {
        $admin = $this->admin();
        $current = $admin->createToken('current-device');
        $admin->createToken('other-device');

        $this->withToken($current->plainTextToken)
            ->postJson('/api/admin/profile/logout-all')
            ->assertOk();

        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_guests_and_normal_users_cannot_manage_admin_profile(): void
    {
        $this->getJson('/api/admin/profile')->assertUnauthorized();
        $this->putJson('/api/admin/profile', [])
            ->assertUnauthorized();
        $this->postJson('/api/admin/profile/change-password', [])
            ->assertUnauthorized();
        $this->postJson('/api/admin/profile/logout-all')
            ->assertUnauthorized();

        $user = User::factory()->create([
            'role' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/admin/profile')->assertForbidden();
        $this->putJson('/api/admin/profile', [])
            ->assertForbidden();
        $this->postJson('/api/admin/profile/change-password', [])
            ->assertForbidden();
        $this->postJson('/api/admin/profile/logout-all')
            ->assertForbidden();
    }
}