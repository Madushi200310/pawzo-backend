<?php

namespace Tests\Feature\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_view_their_profile(): void
    {
        $user = User::factory()->create([
            'name'  => 'Madushi Perera',
            'email' => 'madushi@example.com',
            'phone' => '0771234567',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/profile');

        $response->assertOk()
            ->assertJsonPath('message', 'Profile fetched successfully.')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.name', 'Madushi Perera')
            ->assertJsonPath('user.email', 'madushi@example.com')
            ->assertJsonPath('user.phone', '0771234567')
            ->assertJsonMissingPath('user.password');
    }

    public function test_guest_cannot_view_profile(): void
    {
        $response = $this->getJson('/api/profile');

        $response->assertUnauthorized();
    }
}