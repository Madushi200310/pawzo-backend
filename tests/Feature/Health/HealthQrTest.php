<?php

namespace Tests\Feature\Health;

use App\Models\HealthRecord;
use App\Models\HealthShareToken;
use App\Models\Pet;
use App\Models\User;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthQrTest extends TestCase
{
    use RefreshDatabase;

    // ---------------- Generate ----------------

    public function test_user_can_generate_health_qr_for_their_pet(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-qr", [
                'include' => [
                    'pet_details'     => true,
                    'vaccinations'    => true,
                    'medical_history' => true,
                    'medications'     => false,
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Health QR generated successfully.')
            ->assertJsonStructure(['token', 'public_url', 'qr_image', 'share']);

        $this->assertStringStartsWith('data:image/png;base64,', $response->json('qr_image'));
        $this->assertDatabaseHas('health_share_tokens', ['pet_id' => $pet->id]);
    }

    public function test_include_must_have_at_least_one_true(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-qr", [
                'include' => [
                    'pet_details' => false,
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['include']);
    }

    public function test_generating_new_qr_revokes_previous_token(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-qr", [
                'include' => ['pet_details' => true],
            ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-qr", [
                'include' => ['pet_details' => true],
            ]);

        $this->assertEquals(1, $pet->shareTokens()->count());
    }

    public function test_user_cannot_generate_qr_for_another_users_pet(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $pet   = Pet::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-qr", [
                'include' => ['pet_details' => true],
            ])
            ->assertForbidden();
    }

    // ---------------- Show ----------------

    public function test_user_can_fetch_active_share_token(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-qr", [
                'include' => ['pet_details' => true],
            ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/pets/{$pet->id}/health-qr")
            ->assertOk()
            ->assertJsonStructure(['token', 'public_url', 'qr_image']);
    }

    public function test_show_returns_null_when_no_active_token(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/pets/{$pet->id}/health-qr")
            ->assertOk()
            ->assertJsonPath('share', null);
    }

    // ---------------- Revoke ----------------

    public function test_user_can_revoke_share_token(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-qr", [
                'include' => ['pet_details' => true],
            ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/pets/{$pet->id}/health-qr")
            ->assertOk();

        $this->assertEquals(0, $pet->shareTokens()->count());
    }

    // ---------------- Public access ----------------

    public function test_public_link_returns_shared_data(): void
    {
        $user = User::factory()->create(['name' => 'Madushi Perera']);
        $pet  = Pet::factory()->create([
            'user_id' => $user->id,
            'name'    => 'Rocky',
            'type'    => 'Dog',
        ]);

        $vacc = Vaccination::factory()->create([
            'pet_id'       => $pet->id,
            'vaccine_name' => 'Rabies',
        ]);

        $token = $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-qr", [
                'include' => [
                    'pet_details'  => true,
                    'vaccinations' => true,
                ],
            ])
            ->json('token');

        $response = $this->getJson("/api/public/pets/{$token}");

        $response->assertOk()
            ->assertJsonPath('pet.name', 'Rocky')
            ->assertJsonPath('pet.type', 'Dog')
            ->assertJsonPath('shared_by.owner_name', 'Madushi Perera');

        $this->assertCount(1, $response->json('vaccinations'));
    }

    public function test_public_link_respects_include_flags(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        HealthRecord::factory()->create([
            'pet_id'      => $pet->id,
            'record_type' => 'medication',
            'medication_name' => 'Antibiotics',
        ]);

        $token = $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-qr", [
                'include' => [
                    'pet_details' => true,
                    'medications' => false, // explicitly excluded
                ],
            ])
            ->json('token');

        $response = $this->getJson("/api/public/pets/{$token}");

        $response->assertOk();
        $this->assertArrayNotHasKey('medications', $response->json());
    }

    public function test_public_link_returns_404_for_invalid_token(): void
    {
        $this->getJson('/api/public/pets/this-token-does-not-exist')
            ->assertNotFound();
    }

    public function test_public_link_returns_410_for_expired_token(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $share = HealthShareToken::create([
            'pet_id'     => $pet->id,
            'user_id'    => $user->id,
            'token'      => 'expired-token-xyz',
            'include'    => ['pet_details' => true],
            'expires_at' => now()->subDay(),
        ]);

        $this->getJson("/api/public/pets/{$share->token}")
            ->assertStatus(410);
    }

    public function test_public_link_increments_view_count(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $token = $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-qr", [
                'include' => ['pet_details' => true],
            ])
            ->json('token');

        $this->getJson("/api/public/pets/{$token}")->assertOk();
        $this->getJson("/api/public/pets/{$token}")->assertOk();

        $share = HealthShareToken::where('token', $token)->first();
        $this->assertEquals(2, $share->view_count);
        $this->assertNotNull($share->last_viewed_at);
    }

    // ---------------- Guest ----------------

    public function test_guest_cannot_generate_qr(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->postJson("/api/pets/{$pet->id}/health-qr", [
            'include' => ['pet_details' => true],
        ])->assertUnauthorized();
    }
}