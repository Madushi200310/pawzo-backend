<?php

namespace Tests\Feature\Health;

use App\Models\HealthRecord;
use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HealthRecordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    // ---------------- Index ----------------

    public function test_user_can_list_health_records_for_their_pet(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);
        HealthRecord::factory()->count(3)->create(['pet_id' => $pet->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/pets/{$pet->id}/health-records")
            ->assertOk()
            ->assertJsonPath('message', 'Health records fetched successfully.')
            ->assertJsonCount(3, 'records');
    }

    public function test_user_cannot_list_another_users_pet_records(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $pet   = Pet::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/pets/{$pet->id}/health-records")
            ->assertForbidden();
    }

    public function test_can_filter_records_by_type(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        HealthRecord::factory()->create(['pet_id' => $pet->id, 'record_type' => 'vet_visit']);
        HealthRecord::factory()->create(['pet_id' => $pet->id, 'record_type' => 'medication']);
        HealthRecord::factory()->create(['pet_id' => $pet->id, 'record_type' => 'medication']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/pets/{$pet->id}/health-records?record_type=medication")
            ->assertOk()
            ->assertJsonCount(2, 'records');
    }

    // ---------------- Store ----------------

    public function test_user_can_create_medical_history_record(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-records", [
                'record_type' => 'medical_history',
                'title'       => 'Allergic to chicken',
                'description' => 'Noticed after switching food brands.',
                'date'        => '2024-03-15',
            ])
            ->assertCreated()
            ->assertJsonPath('record.title', 'Allergic to chicken')
            ->assertJsonPath('record.record_type', 'medical_history');
    }

    public function test_user_can_create_vet_visit_record(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-records", [
                'record_type' => 'vet_visit',
                'title'       => 'Annual checkup',
                'vet_name'    => 'Dr. Perera',
                'clinic_name' => 'Pawzo Vet Clinic',
                'date'        => '2024-05-01',
            ])
            ->assertCreated()
            ->assertJsonPath('record.vet_name', 'Dr. Perera');
    }

    public function test_vet_visit_requires_vet_name(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-records", [
                'record_type' => 'vet_visit',
                'title'       => 'Checkup',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vet_name']);
    }

    public function test_medication_requires_name_and_start_date(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-records", [
                'record_type' => 'medication',
                'title'       => 'Antibiotics',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['medication_name', 'start_date']);
    }

    public function test_record_type_must_be_valid(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-records", [
                'record_type' => 'invalid_type',
                'title'       => 'Something',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['record_type']);
    }

    // ---------------- Show / Update / Destroy ----------------

    public function test_user_can_view_record(): void
    {
        $user   = User::factory()->create();
        $pet    = Pet::factory()->create(['user_id' => $user->id]);
        $record = HealthRecord::factory()->create(['pet_id' => $pet->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/pets/{$pet->id}/health-records/{$record->id}")
            ->assertOk()
            ->assertJsonPath('record.id', $record->id);
    }

    public function test_user_can_update_record(): void
    {
        $user   = User::factory()->create();
        $pet    = Pet::factory()->create(['user_id' => $user->id]);
        $record = HealthRecord::factory()->create(['pet_id' => $pet->id, 'title' => 'Old']);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/pets/{$pet->id}/health-records/{$record->id}", ['title' => 'New'])
            ->assertOk()
            ->assertJsonPath('record.title', 'New');
    }

    public function test_user_can_soft_delete_record(): void
    {
        $user   = User::factory()->create();
        $pet    = Pet::factory()->create(['user_id' => $user->id]);
        $record = HealthRecord::factory()->create(['pet_id' => $pet->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/pets/{$pet->id}/health-records/{$record->id}")
            ->assertOk();

        $this->assertSoftDeleted('health_records', ['id' => $record->id]);
    }

    public function test_record_must_belong_to_the_pet(): void
    {
        $user  = User::factory()->create();
        $petA  = Pet::factory()->create(['user_id' => $user->id]);
        $petB  = Pet::factory()->create(['user_id' => $user->id]);
        $record = HealthRecord::factory()->create(['pet_id' => $petA->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/pets/{$petB->id}/health-records/{$record->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['record']);
    }

    // ---------------- Summary ----------------

    public function test_summary_returns_counts_by_type(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        HealthRecord::factory()->count(3)->create(['pet_id' => $pet->id, 'record_type' => 'vet_visit']);
        HealthRecord::factory()->count(2)->create(['pet_id' => $pet->id, 'record_type' => 'medication']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/pets/{$pet->id}/health-records/summary")
            ->assertOk()
            ->assertJsonPath('summary.vet_visit', 3)
            ->assertJsonPath('summary.medication', 2)
            ->assertJsonPath('summary.disease', 0)
            ->assertJsonPath('total', 5);
    }

    // ---------------- Documents ----------------

    public function test_user_can_upload_documents(): void
    {
        $user   = User::factory()->create();
        $pet    = Pet::factory()->create(['user_id' => $user->id]);
        $record = HealthRecord::factory()->create(['pet_id' => $pet->id]);

        $docs = [
            UploadedFile::fake()->create('report.pdf', 200, 'application/pdf'),
            UploadedFile::fake()->image('scan.jpg'),
        ];

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-records/{$record->id}/documents", [
                'documents' => $docs,
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Documents uploaded successfully.');

        $this->assertEquals(2, $record->documents()->count());
    }

    public function test_cannot_upload_more_than_5_documents(): void
    {
        $user   = User::factory()->create();
        $pet    = Pet::factory()->create(['user_id' => $user->id]);
        $record = HealthRecord::factory()->create(['pet_id' => $pet->id]);

        for ($i = 0; $i < 4; $i++) {
            $record->documents()->create([
                'path'          => "fake/{$i}.pdf",
                'original_name' => "doc{$i}.pdf",
                'mime_type'     => 'application/pdf',
                'size'          => 1000,
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-records/{$record->id}/documents", [
                'documents' => [
                    UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
                    UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents']);
    }

    public function test_document_must_be_valid_type(): void
    {
        $user   = User::factory()->create();
        $pet    = Pet::factory()->create(['user_id' => $user->id]);
        $record = HealthRecord::factory()->create(['pet_id' => $pet->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-records/{$record->id}/documents", [
                'documents' => [UploadedFile::fake()->create('script.exe', 100, 'application/octet-stream')],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documents.0']);
    }

    public function test_user_can_delete_document(): void
    {
        $user   = User::factory()->create();
        $pet    = Pet::factory()->create(['user_id' => $user->id]);
        $record = HealthRecord::factory()->create(['pet_id' => $pet->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/health-records/{$record->id}/documents", [
                'documents' => [UploadedFile::fake()->image('scan.jpg')],
            ]);

        $doc = $record->documents()->first();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/pets/{$pet->id}/health-records/{$record->id}/documents/{$doc->id}")
            ->assertOk();

        $this->assertDatabaseMissing('health_documents', ['id' => $doc->id]);
    }

    // ---------------- Guest ----------------

    public function test_guest_cannot_access_health_records(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->getJson("/api/pets/{$pet->id}/health-records")->assertUnauthorized();
    }
}