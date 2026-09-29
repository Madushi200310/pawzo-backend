<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_name_and_phone(): void
    {
        $user = User::factory()->create([
            'name'  => 'Old Name',
            'phone' => '0770000000',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', [
                'name'  => 'New Name',
                'phone' => '0771234567',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Profile updated successfully.')
            ->assertJsonPath('user.name', 'New Name')
            ->assertJsonPath('user.phone', '0771234567');

        $this->assertDatabaseHas('users', [
            'id'    => $user->id,
            'name'  => 'New Name',
            'phone' => '0771234567',
        ]);
    }

    public function test_user_can_update_only_name(): void
    {
        $user = User::factory()->create([
            'name'  => 'Old Name',
            'phone' => '0770000000',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => 'Only Name Changed',
            ]);

        $response->assertOk()
            ->assertJsonPath('user.name', 'Only Name Changed')
            ->assertJsonPath('user.phone', '0770000000'); // unchanged
    }

    public function test_name_is_required_if_present(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => '', // empty
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_name_cannot_exceed_255_chars(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->putJson('/api/profile', [
                'name' => str_repeat('a', 256),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_guest_cannot_update_profile(): void
    {
        $response = $this->putJson('/api/profile', [
            'name' => 'Hacker',
        ]);

        $response->assertUnauthorized();
    }
}