<?php

namespace Tests\Feature\Admin;

use App\Models\Pet;
use App\Models\PetReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PetReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function user(): User
    {
        return User::factory()->create(['role' => 'user']);
    }

    // ---------------- User submits a report ----------------

    public function test_user_can_report_another_users_pet(): void
    {
        $reporter = $this->user();
        $owner    = $this->user();
        $pet      = Pet::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($reporter, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/report", [
                'reason' => 'fake_profile',
                'notes'  => 'This looks like a duplicate account.',
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Report submitted successfully. Our team will review it.');

        $this->assertDatabaseHas('pet_reports', [
            'pet_id'      => $pet->id,
            'reporter_id' => $reporter->id,
            'reason'      => 'fake_profile',
            'status'      => 'pending',
        ]);
    }

    public function test_user_cannot_report_their_own_pet(): void
    {
        $user = $this->user();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/report", [
                'reason' => 'fake_profile',
            ])
            ->assertStatus(422);
    }

    public function test_user_cannot_report_same_pet_twice_with_pending(): void
    {
        $reporter = $this->user();
        $owner    = $this->user();
        $pet      = Pet::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($reporter, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/report", ['reason' => 'fake_profile'])
            ->assertCreated();

        $this->actingAs($reporter, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/report", ['reason' => 'scam'])
            ->assertStatus(422);
    }

    public function test_report_requires_valid_reason(): void
    {
        $reporter = $this->user();
        $owner    = $this->user();
        $pet      = Pet::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($reporter, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/report", ['reason' => 'nonsense'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_guest_cannot_report_pet(): void
    {
        $pet = Pet::factory()->create();

        $this->postJson("/api/pets/{$pet->id}/report", ['reason' => 'fake_profile'])
            ->assertUnauthorized();
    }

    // ---------------- Admin views reports ----------------

    public function test_admin_can_list_reports(): void
    {
        $admin = $this->admin();
        $pet   = Pet::factory()->create();
        PetReport::factory()->count(3)->create([
            'pet_id'      => $pet->id,
            'reporter_id' => $this->user()->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/pet-reports')
            ->assertOk()
            ->assertJsonPath('message', 'Reports fetched successfully.')
            ->assertJsonCount(3, 'reports.data');
    }

    public function test_admin_can_filter_reports_by_status(): void
    {
        $admin = $this->admin();
        $pet   = Pet::factory()->create();

        PetReport::factory()->count(2)->create(['pet_id' => $pet->id, 'reporter_id' => $this->user()->id, 'status' => 'pending']);
        PetReport::factory()->count(3)->create(['pet_id' => $pet->id, 'reporter_id' => $this->user()->id, 'status' => 'resolved']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/pet-reports?status=pending')
            ->assertOk();

        $this->assertCount(2, $response->json('reports.data'));
    }

    // ---------------- Admin reviews a report ----------------

    public function test_admin_can_mark_report_as_resolved(): void
    {
        $admin = $this->admin();
        $pet   = Pet::factory()->create();
        $report = PetReport::factory()->create([
            'pet_id'      => $pet->id,
            'reporter_id' => $this->user()->id,
            'status'      => 'pending',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/pet-reports/{$report->id}", [
                'status'       => 'resolved',
                'review_notes' => 'Fake account confirmed and removed.',
            ])
            ->assertOk()
            ->assertJsonPath('report.status', 'resolved')
            ->assertJsonPath('report.review_notes', 'Fake account confirmed and removed.');

        $this->assertDatabaseHas('pet_reports', [
            'id'          => $report->id,
            'status'      => 'resolved',
            'reviewed_by' => $admin->id,
        ]);
    }

    public function test_admin_can_dismiss_report(): void
    {
        $admin = $this->admin();
        $pet   = Pet::factory()->create();
        $report = PetReport::factory()->create([
            'pet_id'      => $pet->id,
            'reporter_id' => $this->user()->id,
            'status'      => 'pending',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/pet-reports/{$report->id}", ['status' => 'dismissed'])
            ->assertOk()
            ->assertJsonPath('report.status', 'dismissed');
    }

    public function test_review_status_must_be_valid(): void
    {
        $admin  = $this->admin();
        $pet    = Pet::factory()->create();
        $report = PetReport::factory()->create([
            'pet_id'      => $pet->id,
            'reporter_id' => $this->user()->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/pet-reports/{$report->id}", ['status' => 'invalid'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_non_admin_cannot_review_report(): void
    {
        $user   = $this->user();
        $pet    = Pet::factory()->create();
        $report = PetReport::factory()->create([
            'pet_id'      => $pet->id,
            'reporter_id' => $this->user()->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/admin/pet-reports/{$report->id}", ['status' => 'resolved'])
            ->assertForbidden();
    }
}