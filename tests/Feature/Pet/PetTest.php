<?php

namespace Tests\Feature\Pet;

use App\Models\Pet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    // ---------------- Index ----------------

    public function test_user_sees_only_their_own_pets(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();

        Pet::factory()->count(3)->create(['user_id' => $user->id]);
        Pet::factory()->count(2)->create(['user_id' => $other->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/pets');

        $response->assertOk()
            ->assertJsonPath('message', 'Pets fetched successfully.')
            ->assertJsonCount(3, 'pets');
    }

    // ---------------- Store ----------------

    public function test_user_can_create_a_pet(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/pets', [
            'name'          => 'Rocky',
            'type'          => 'Dog',
            'breed'         => 'Labrador',
            'gender'        => 'male',
            'date_of_birth' => '2022-05-10',
            'color'         => 'Brown',
            'weight'        => 12.5,
            'characteristics' => 'Friendly and playful.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Pet created successfully.')
            ->assertJsonPath('pet.name', 'Rocky')
            ->assertJsonPath('pet.gender', 'male');

        $this->assertDatabaseHas('pets', [
            'user_id' => $user->id,
            'name'    => 'Rocky',
        ]);
    }

    public function test_pet_name_and_type_are_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/pets', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'type']);
    }

    public function test_gender_must_be_valid_value(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/pets', [
                'name'   => 'Milo',
                'type'   => 'Cat',
                'gender' => 'invalid',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['gender']);
    }

    public function test_date_of_birth_cannot_be_in_future(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/pets', [
                'name'          => 'Future',
                'type'          => 'Dog',
                'date_of_birth' => now()->addDay()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date_of_birth']);
    }

    // ---------------- Show ----------------

    public function test_user_can_view_their_own_pet(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/pets/{$pet->id}")
            ->assertOk()
            ->assertJsonPath('pet.id', $pet->id);
    }

    public function test_user_cannot_view_another_users_pet(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $pet   = Pet::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/pets/{$pet->id}")
            ->assertForbidden();
    }

    // ---------------- Update ----------------

    public function test_user_can_update_their_pet(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id, 'name' => 'Old']);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/pets/{$pet->id}", ['name' => 'New'])
            ->assertOk()
            ->assertJsonPath('pet.name', 'New');

        $this->assertDatabaseHas('pets', ['id' => $pet->id, 'name' => 'New']);
    }

    public function test_user_cannot_update_another_users_pet(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $pet   = Pet::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/pets/{$pet->id}", ['name' => 'Hacked'])
            ->assertForbidden();
    }

    // ---------------- Destroy ----------------

    public function test_user_can_soft_delete_their_pet(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/pets/{$pet->id}")
            ->assertOk();

        $this->assertSoftDeleted('pets', ['id' => $pet->id]);
    }

    // ---------------- Photos ----------------

    public function test_user_can_upload_photos(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $photos = [
            UploadedFile::fake()->image('a.jpg', 600, 600),
            UploadedFile::fake()->image('b.png', 600, 600),
        ];

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/photos", ['photos' => $photos]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Photos uploaded successfully.');

        $this->assertEquals(2, $pet->photos()->count());
        Storage::disk('public')->assertExists($pet->photos()->first()->path);
    }

    public function test_first_uploaded_photo_is_marked_primary(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/photos", [
                'photos' => [UploadedFile::fake()->image('a.jpg')],
            ])
            ->assertCreated();

        $this->assertTrue($pet->photos()->first()->is_primary);
    }

    public function test_cannot_upload_more_than_5_photos_total(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        // Pre-fill 4 photos
        for ($i = 0; $i < 4; $i++) {
            $pet->photos()->create(['path' => "pets/{$pet->id}/x{$i}.jpg"]);
        }

        // Try to upload 2 more (would total 6)
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/photos", [
                'photos' => [
                    UploadedFile::fake()->image('a.jpg'),
                    UploadedFile::fake()->image('b.jpg'),
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photos']);
    }

    public function test_photo_must_be_valid_type(): void
    {
        $user = User::factory()->create();
        $pet  = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/photos", [
                'photos' => [UploadedFile::fake()->create('doc.pdf', 100)],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photos.0']);
    }

    public function test_user_can_delete_a_photo(): void
    {
        $user  = User::factory()->create();
        $pet   = Pet::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/photos", [
                'photos' => [UploadedFile::fake()->image('a.jpg')],
            ]);

        $photo = $pet->photos()->first();
        $path  = $photo->path;

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/pets/{$pet->id}/photos/{$photo->id}")
            ->assertOk();

        $this->assertDatabaseMissing('pet_photos', ['id' => $photo->id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_user_cannot_upload_photo_to_another_users_pet(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $pet   = Pet::factory()->create(['user_id' => $other->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/pets/{$pet->id}/photos", [
                'photos' => [UploadedFile::fake()->image('a.jpg')],
            ])
            ->assertForbidden();
    }

    // ---------------- Guest ----------------

    public function test_guest_cannot_access_pets(): void
    {
        $this->getJson('/api/pets')->assertUnauthorized();
        $this->postJson('/api/pets', [])->assertUnauthorized();
    }
}