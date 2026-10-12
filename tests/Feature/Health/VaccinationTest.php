<?php

namespace Tests\Feature\Health;

use App\Models\Pet;
use App\Models\User;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VaccinationTest extends TestCase
{
    use RefreshDatabase;

    // ---------------- Index ----------------

    public function test_user_can_list_vaccinations(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);
        Vaccination::factory()->count(3)->create(['pet_id' => $pet->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/pets/{$pet->id}/vaccinations")
            ->assertOk()
            ->assertJsonPath('message', 'Vaccinations fetched successfully.')
            ->assertJsonCount(3, 'vaccinations');
    }

    public function test_user_cannot_list_another_users_vaccinations(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $pet   = Pet::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/pets/{$pet->id}/vaccinations")
            ->assertForbidden();
    }

    // ---------------- Store ----------------

    public function test_user_can_create_vaccination(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/vaccinations", [
                'vaccine_name'  => 'Rabies',
                'given_date'    => '2024-01-15',
                'next_due_date' => '2025-01-15',
                'vet_name'      => 'Dr. Perera',
                'batch_number'  => 'RB-2024-001',
                'notes'         => 'No side effects observed.',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Vaccination recorded successfully.')
            ->assertJsonPath('vaccination.vaccine_name', 'Rabies');
    }

    public function test_vaccine_name_and_given_date_are_required(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/vaccinations", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vaccine_name', 'given_date']);
    }

    public function test_given_date_cannot_be_in_future(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/vaccinations", [
                'vaccine_name' => 'Rabies',
                'given_date'   => now()->addDay()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['given_date']);
    }

    public function test_next_due_date_must_be_after_given_date(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/vaccinations", [
                'vaccine_name'  => 'Rabies',
                'given_date'    => '2024-06-01',
                'next_due_date' => '2024-05-01', // before given
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['next_due_date']);
    }

    // ---------------- Show / Update / Destroy ----------------

    public function test_user_can_view_vaccination(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);
        $vax  = Vaccination::factory()->create(['pet_id' => $pet->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/pets/{$pet->id}/vaccinations/{$vax->id}")
            ->assertOk()
            ->assertJsonPath('vaccination.id', $vax->id);
    }

    public function test_user_can_update_vaccination(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);
        $vax  = Vaccination::factory()->create(['pet_id' => $pet->id, 'vaccine_name' => 'Old']);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/pets/{$pet->id}/vaccinations/{$vax->id}", ['vaccine_name' => 'New'])
            ->assertOk()
            ->assertJsonPath('vaccination.vaccine_name', 'New');
    }

    public function test_user_can_soft_delete_vaccination(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);
        $vax  = Vaccination::factory()->create(['pet_id' => $pet->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/pets/{$pet->id}/vaccinations/{$vax->id}")
            ->assertOk();

        $this->assertSoftDeleted('vaccinations', ['id' => $vax->id]);
    }

    public function test_vaccination_must_belong_to_the_pet(): void
    {
        $user = User::factory()->create();
        $petA = Pet::factory()->create(['user_id' => $user->id]);
        $petB = Pet::factory()->create(['user_id' => $user->id]);
        $vax  = Vaccination::factory()->create(['pet_id' => $petA->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/pets/{$petB->id}/vaccinations/{$vax->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vaccination']);
    }

    // ---------------- Upcoming ----------------

    public function test_upcoming_returns_only_future_and_overdue_items(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        // No next_due_date → should be excluded
        Vaccination::factory()->create(['pet_id' => $pet->id, 'next_due_date' => null]);

        // Overdue
        Vaccination::factory()->create([
            'pet_id'        => $pet->id,
            'given_date'    => now()->subYear()->toDateString(),
            'next_due_date' => now()->subWeek()->toDateString(),
        ]);

        // Upcoming in 10 days
        Vaccination::factory()->create([
            'pet_id'        => $pet->id,
            'given_date'    => now()->subYear()->toDateString(),
            'next_due_date' => now()->addDays(10)->toDateString(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/pets/{$pet->id}/vaccinations/upcoming")
            ->assertOk();

        $this->assertCount(2, $response->json('items'));
        $this->assertEquals(1, $response->json('overdue'));
    }

    public function test_upcoming_items_are_sorted_by_due_date(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        Vaccination::factory()->create([
            'pet_id'        => $pet->id,
            'given_date'    => now()->subYear()->toDateString(),
            'next_due_date' => now()->addDays(30)->toDateString(),
        ]);
        Vaccination::factory()->create([
            'pet_id'        => $pet->id,
            'given_date'    => now()->subYear()->toDateString(),
            'next_due_date' => now()->addDays(5)->toDateString(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/pets/{$pet->id}/vaccinations/upcoming")
            ->assertOk();

        $items = $response->json('items');
        $this->assertLessThan(
            strtotime($items[1]['next_due_date']),
            strtotime($items[0]['next_due_date'])
        );
    }

    // ---------------- Guest ----------------

    public function test_guest_cannot_access_vaccinations(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->getJson("/api/pets/{$pet->id}/vaccinations")->assertUnauthorized();
    }
}