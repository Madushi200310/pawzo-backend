<?php

namespace Tests\Feature\Health;

use App\Models\HealthRecord;
use App\Models\Pet;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Vaccination;
use App\Services\ReminderGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReminderTest extends TestCase
{
    use RefreshDatabase;

    // ---------------- Index ----------------

    public function test_user_can_list_all_their_reminders(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);
        Reminder::factory()->count(3)->create(['pet_id' => $pet->id, 'user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/reminders')
            ->assertOk()
            ->assertJsonPath('message', 'Reminders fetched successfully.')
            ->assertJsonCount(3, 'reminders');
    }

    public function test_user_only_sees_their_own_reminders(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $pet1  = Pet::factory()->create(['user_id' => $user->id]);
        $pet2  = Pet::factory()->create(['user_id' => $other->id]);

        Reminder::factory()->count(2)->create(['pet_id' => $pet1->id, 'user_id' => $user->id]);
        Reminder::factory()->count(5)->create(['pet_id' => $pet2->id, 'user_id' => $other->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/reminders')
            ->assertOk()
            ->assertJsonCount(2, 'reminders');
    }

    public function test_can_filter_by_status_pending(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        Reminder::factory()->create(['pet_id' => $pet->id, 'user_id' => $user->id]);
        Reminder::factory()->create(['pet_id' => $pet->id, 'user_id' => $user->id, 'is_completed' => true]);
        Reminder::factory()->create(['pet_id' => $pet->id, 'user_id' => $user->id, 'is_dismissed' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/reminders?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'reminders');
    }

    public function test_can_filter_by_status_overdue(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        Reminder::factory()->create([
            'pet_id'   => $pet->id,
            'user_id'  => $user->id,
            'due_date' => now()->subDays(5)->toDateString(),
        ]);
        Reminder::factory()->create([
            'pet_id'   => $pet->id,
            'user_id'  => $user->id,
            'due_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/reminders?status=overdue')
            ->assertOk()
            ->assertJsonCount(1, 'reminders');
    }

    public function test_can_filter_by_type(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        Reminder::factory()->count(2)->create(['pet_id' => $pet->id, 'user_id' => $user->id, 'type' => 'vaccination']);
        Reminder::factory()->count(3)->create(['pet_id' => $pet->id, 'user_id' => $user->id, 'type' => 'grooming']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/reminders?type=vaccination')
            ->assertOk()
            ->assertJsonCount(2, 'reminders');
    }

    // ---------------- For Pet ----------------

    public function test_user_can_list_reminders_for_a_pet(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);
        Reminder::factory()->count(4)->create(['pet_id' => $pet->id, 'user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/pets/{$pet->id}/reminders")
            ->assertOk()
            ->assertJsonCount(4, 'reminders');
    }

    public function test_user_cannot_list_reminders_for_another_users_pet(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $pet   = Pet::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/pets/{$pet->id}/reminders")
            ->assertForbidden();
    }

    // ---------------- Store ----------------

    public function test_user_can_create_manual_reminder(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/reminders", [
                'type'     => 'grooming',
                'title'    => 'Grooming appointment',
                'due_date' => now()->addDays(7)->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('reminder.title', 'Grooming appointment')
            ->assertJsonPath('reminder.type', 'grooming');

        $this->assertDatabaseHas('reminders', [
            'pet_id'  => $pet->id,
            'user_id' => $user->id,
            'type'    => 'grooming',
        ]);
    }

    public function test_manual_reminder_requires_valid_type(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/reminders", [
                'type'     => 'invalid',
                'title'    => 'X',
                'due_date' => now()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_manual_reminder_requires_title_and_due_date(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/reminders", [
                'type' => 'grooming',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'due_date']);
    }

    public function test_user_cannot_create_reminder_on_another_users_pet(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $pet   = Pet::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/reminders", [
                'type'     => 'grooming',
                'title'    => 'X',
                'due_date' => now()->toDateString(),
            ])
            ->assertForbidden();
    }

    // ---------------- Show / Update ----------------

    public function test_user_can_view_their_reminder(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);
        $r    = Reminder::factory()->create(['pet_id' => $pet->id, 'user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/reminders/{$r->id}")
            ->assertOk()
            ->assertJsonPath('reminder.id', $r->id);
    }

    public function test_user_cannot_view_another_users_reminder(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $pet   = Pet::factory()->create(['user_id' => $other->id]);
        $r     = Reminder::factory()->create(['pet_id' => $pet->id, 'user_id' => $other->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/reminders/{$r->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reminder']);
    }

    public function test_user_can_update_reminder(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);
        $r    = Reminder::factory()->create(['pet_id' => $pet->id, 'user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/reminders/{$r->id}", ['title' => 'Updated'])
            ->assertOk()
            ->assertJsonPath('reminder.title', 'Updated');
    }

    public function test_user_cannot_update_another_users_reminder(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $pet   = Pet::factory()->create(['user_id' => $other->id]);
        $r     = Reminder::factory()->create(['pet_id' => $pet->id, 'user_id' => $other->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/reminders/{$r->id}", ['title' => 'Hacked'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reminder']);
    }

    // ---------------- Complete / Dismiss / Delete ----------------

    public function test_user_can_complete_reminder(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);
        $r    = Reminder::factory()->create(['pet_id' => $pet->id, 'user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/reminders/{$r->id}/complete")
            ->assertOk()
            ->assertJsonPath('reminder.is_completed', true);

        $this->assertNotNull($r->fresh()->completed_at);
    }

    public function test_user_can_dismiss_reminder(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);
        $r    = Reminder::factory()->create(['pet_id' => $pet->id, 'user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/reminders/{$r->id}/dismiss")
            ->assertOk()
            ->assertJsonPath('reminder.is_dismissed', true);
    }

    public function test_user_can_delete_reminder(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);
        $r    = Reminder::factory()->create(['pet_id' => $pet->id, 'user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/reminders/{$r->id}")
            ->assertOk();

        $this->assertDatabaseMissing('reminders', ['id' => $r->id]);
    }

    public function test_user_cannot_delete_another_users_reminder(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $pet   = Pet::factory()->create(['user_id' => $other->id]);
        $r     = Reminder::factory()->create(['pet_id' => $pet->id, 'user_id' => $other->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/reminders/{$r->id}")
            ->assertStatus(422);
    }

    // ---------------- Auto-generation ----------------

    public function test_service_generates_reminder_from_upcoming_vaccination(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        Vaccination::factory()->create([
            'pet_id'        => $pet->id,
            'vaccine_name'  => 'Rabies',
            'given_date'    => now()->subMonths(11)->toDateString(),
            'next_due_date' => now()->addDays(15)->toDateString(),
        ]);

        $result = app(ReminderGeneratorService::class)->generateForPet($pet);

        $this->assertEquals(1, $result);
        $this->assertDatabaseHas('reminders', [
            'pet_id' => $pet->id,
            'type'   => 'vaccination',
        ]);
    }

    public function test_service_does_not_duplicate_existing_reminders(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        Vaccination::factory()->create([
            'pet_id'        => $pet->id,
            'given_date'    => now()->subMonths(11)->toDateString(),
            'next_due_date' => now()->addDays(15)->toDateString(),
        ]);

        $service = app(ReminderGeneratorService::class);
        $service->generateForPet($pet);
        $service->generateForPet($pet); // run twice

        $this->assertEquals(1, Reminder::where('pet_id', $pet->id)->count());
    }

    public function test_service_ignores_vaccinations_due_more_than_30_days_away(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        Vaccination::factory()->create([
            'pet_id'        => $pet->id,
            'given_date'    => now()->subMonths(10)->toDateString(),
            'next_due_date' => now()->addDays(60)->toDateString(),
        ]);

        $result = app(ReminderGeneratorService::class)->generateForPet($pet);

        $this->assertEquals(0, $result);
    }

    public function test_service_ignores_past_due_vaccinations(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        Vaccination::factory()->create([
            'pet_id'        => $pet->id,
            'given_date'    => now()->subMonths(13)->toDateString(),
            'next_due_date' => now()->subDays(10)->toDateString(),
        ]);

        $result = app(ReminderGeneratorService::class)->generateForPet($pet);

        $this->assertEquals(0, $result);
    }

    public function test_service_generates_reminder_for_ongoing_medication(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        HealthRecord::factory()->create([
            'pet_id'          => $pet->id,
            'record_type'     => 'medication',
            'medication_name' => 'Antibiotics',
            'is_ongoing'      => true,
            'end_date'        => now()->addDays(10)->toDateString(),
        ]);

        $result = app(ReminderGeneratorService::class)->generateForPet($pet);

        $this->assertEquals(1, $result);
        $this->assertDatabaseHas('reminders', [
            'pet_id' => $pet->id,
            'type'   => 'medication',
        ]);
    }

    public function test_service_ignores_medication_without_end_date(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        HealthRecord::factory()->create([
            'pet_id'          => $pet->id,
            'record_type'     => 'medication',
            'medication_name' => 'Antibiotics',
            'is_ongoing'      => true,
            'end_date'        => null,
        ]);

        $result = app(ReminderGeneratorService::class)->generateForPet($pet);

        $this->assertEquals(0, $result);
    }

    public function test_artisan_command_runs_successfully(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        Vaccination::factory()->create([
            'pet_id'        => $pet->id,
            'given_date'    => now()->subMonths(11)->toDateString(),
            'next_due_date' => now()->addDays(20)->toDateString(),
        ]);

        $this->artisan('reminders:generate')
            ->expectsOutput('Scanning pets...')
            ->assertSuccessful();

        $this->assertDatabaseHas('reminders', [
            'pet_id' => $pet->id,
            'type'   => 'vaccination',
        ]);
    }

    // ---------------- Guest ----------------

    public function test_guest_cannot_access_reminders(): void
    {
        $this->getJson('/api/reminders')->assertUnauthorized();
    }
}