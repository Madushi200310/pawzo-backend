<?php

namespace Tests\Feature\Admin;

use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPetTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    public function test_admin_can_list_pets(): void
    {
        $admin = $this->admin();
        Pet::factory()->count(4)->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/pets')
            ->assertOk()
            ->assertJsonPath('message', 'Pets fetched successfully.')
            ->assertJsonCount(4, 'pets.data');
    }

    public function test_non_admin_cannot_list_pets(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/pets')
            ->assertForbidden();
    }

    public function test_admin_can_search_pets_by_name(): void
    {
        $admin = $this->admin();
        Pet::factory()->create(['name' => 'Rocky']);
        Pet::factory()->create(['name' => 'Milo']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/pets?search=Rocky')
            ->assertOk();

        $this->assertCount(1, $response->json('pets.data'));
    }

    public function test_admin_can_search_pets_by_owner_email(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create(['email' => 'unique@example.com', 'role' => 'user']);
        Pet::factory()->create(['user_id' => $owner->id]);
        Pet::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/pets?search=unique')
            ->assertOk();

        $this->assertCount(1, $response->json('pets.data'));
    }

    public function test_admin_can_filter_by_type(): void
    {
        $admin = $this->admin();
        Pet::factory()->count(3)->create(['type' => 'Dog']);
        Pet::factory()->count(2)->create(['type' => 'Cat']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/pets?type=Dog')
            ->assertOk();

        $this->assertCount(3, $response->json('pets.data'));
    }

    public function test_admin_does_not_see_trashed_pets_by_default(): void
    {
        $admin = $this->admin();
        $pet = Pet::factory()->create();
        $pet->delete();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/pets')
            ->assertOk();

        $ids = collect($response->json('pets.data'))->pluck('id');
        $this->assertNotContains($pet->id, $ids);
    }

    public function test_admin_can_view_trashed_pets(): void
    {
        $admin = $this->admin();
        $pet = Pet::factory()->create();
        $pet->delete();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/pets?status=trashed')
            ->assertOk();

        $ids = collect($response->json('pets.data'))->pluck('id');
        $this->assertContains($pet->id, $ids);
    }

    public function test_admin_can_view_pet_with_owner_and_summary(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create(['role' => 'user']);
        $pet = Pet::factory()->create(['user_id' => $owner->id, 'name' => 'Buddy']);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/pets/{$pet->id}")
            ->assertOk()
            ->assertJsonPath('pet.name', 'Buddy')
            ->assertJsonPath('pet.user.id', $owner->id)
            ->assertJsonStructure(['summary' => ['photos', 'health_records', 'vaccinations', 'reminders', 'reports', 'pending_reports']]);
    }

    public function test_admin_can_soft_delete_pet(): void
    {
        $admin = $this->admin();
        $pet = Pet::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/pets/{$pet->id}")
            ->assertOk();

        $this->assertSoftDeleted('pets', ['id' => $pet->id]);
    }

    public function test_admin_can_restore_trashed_pet(): void
    {
        $admin = $this->admin();
        $pet = Pet::factory()->create();
        $pet->delete();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/pets/{$pet->id}/restore")
            ->assertOk()
            ->assertJsonPath('pet.id', $pet->id);

        $this->assertDatabaseHas('pets', [
            'id'         => $pet->id,
            'deleted_at' => null,
        ]);
    }
}