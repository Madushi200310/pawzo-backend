<?php

namespace Tests\Feature;

use App\Models\PetRental;
use App\Models\PetRentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PetRentalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('Use phpunit.rentals.xml with SQLite :memory:.');
        }
        if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
            throw new \RuntimeException('Run php artisan config:clear before these tests.');
        }

        parent::setUp();
        Storage::fake('public');
    }

    private function login(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'is_active' => true,
            'email_verified_at' => now(),
        ], $attributes))->refresh();
        Sanctum::actingAs($user);

        return $user;
    }

    private function photo(string $extension = 'png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'pet.'.$extension,
            file_get_contents(base_path('tests/Fixtures/LostPets/pet.'.$extension))
        );
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'pet_name' => 'Buddy',
            'pet_type' => 'dog',
            'breed' => 'Labrador',
            'gender' => 'male',
            'age' => '2 years',
            'price_per_day' => '25.00',
            'location_name' => 'Nittambuwa',
            'latitude' => '7.1420000',
            'longitude' => '80.0960000',
            'description' => 'Friendly dog available for weekend stays.',
            'available_from' => now()->addDay()->format('Y-m-d\TH:i:sP'),
            'available_to' => now()->addDays(7)->format('Y-m-d\TH:i:sP'),
            'photos' => [$this->photo()],
        ], $overrides);
    }

    private function createListing(array $overrides = []): PetRental
    {
        $response = $this->postJson('/api/rentals', $this->payload($overrides))->assertCreated();

        return PetRental::findOrFail($response->json('data.id'));
    }

    public function test_guests_cannot_read_or_create_listings(): void
    {
        $this->getJson('/api/rentals')->assertUnauthorized();
        $this->postJson('/api/rentals', [])->assertUnauthorized();
    }

    public function test_unverified_accounts_are_blocked(): void
    {
        $this->login(['email_verified_at' => null]);
        $this->postJson('/api/rentals', $this->payload())->assertForbidden();
    }

    public function test_existing_bearer_token_is_blocked_after_deactivation(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('test')->plainTextToken;
        $user->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/rentals')->assertForbidden();
    }

    public function test_create_persists_owner_location_photos_and_dates(): void
    {
        $owner = $this->login();
        $listing = $this->createListing([
            'available_from' => now()->addDays(2)->format('Y-m-d\TH:i:sP'),
            'available_to' => now()->addDays(9)->format('Y-m-d\TH:i:sP'),
        ]);

        $this->assertSame($owner->id, $listing->user_id);
        $this->assertSame('active', $listing->status);
        $this->assertSame(now()->addDays(2)->toDateString(), $listing->available_from->toDateString());
        Storage::disk('public')->assertExists($listing->photos()->first()->path);
        $this->getJson('/api/rentals/'.$listing->id)->assertOk()
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonCount(1, 'data.photos')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.user');
    }

    public function test_listing_details_expose_public_photo_urls_for_owners(): void
    {
        $this->login();
        $listing = $this->createListing();

        $response = $this->getJson('/api/rentals/'.$listing->id)
            ->assertOk()
            ->assertJsonPath('data.id', $listing->id)
            ->assertJsonPath('data.photos.0.id', $listing->photos()->first()->id);

        $this->assertStringContainsString('/storage/', $response->json('data.photos.0.url'));
    }

    public function test_all_four_requested_image_extensions_are_accepted(): void
    {
        $this->login();
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
            $this->createListing(['photos' => [$this->photo($extension)]]);
        }
    }

    public function test_required_fields_price_coordinates_and_dates_are_validated(): void
    {
        $this->login();
        $this->postJson('/api/rentals', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['pet_name', 'pet_type', 'description', 'price_per_day', 'available_from', 'photos']);

        $this->postJson('/api/rentals', $this->payload([
            'latitude' => 91,
            'longitude' => 181,
            'price_per_day' => '0',
            'available_to' => now()->subDay()->format('Y-m-d\TH:i:sP'),
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'latitude', 'longitude', 'price_per_day', 'available_to',
        ]);

        $this->postJson('/api/rentals', $this->payload([
            'available_from' => '2020-01-02 10:30:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors('available_from');
    }

    public function test_owner_and_status_cannot_be_injected_into_create(): void
    {
        $this->login();
        $this->postJson('/api/rentals', $this->payload([
            'user_id' => 987,
            'status' => 'paused',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'status']);
        $this->assertDatabaseCount('pet_rentals', 0);
    }

    public function test_fake_image_content_and_disallowed_extensions_are_rejected(): void
    {
        $this->login();
        $bad = UploadedFile::fake()->createWithContent('pet.png', '<?php echo "bad";');
        $this->postJson('/api/rentals', $this->payload(['photos' => [$bad]]))
            ->assertUnprocessable()->assertJsonValidationErrors('photos.0');

        $bad = UploadedFile::fake()->createWithContent('pet.php',
            file_get_contents(base_path('tests/Fixtures/LostPets/pet.png')));
        $this->postJson('/api/rentals', $this->payload(['photos' => [$bad]]))
            ->assertUnprocessable()->assertJsonValidationErrors('photos.0');
    }

    public function test_oversized_photos_and_six_photo_creation_are_rejected(): void
    {
        $this->login();
        $this->postJson('/api/rentals', $this->payload(['photos' => [$this->photo()->size(5121)]]))
            ->assertUnprocessable()->assertJsonValidationErrors('photos.0');

        $this->postJson('/api/rentals', $this->payload([
            'photos' => array_map(fn () => $this->photo(), range(1, 6)),
        ]))->assertUnprocessable()->assertJsonValidationErrors('photos');
    }

    public function test_owner_can_update_fields_but_coordinates_must_be_paired(): void
    {
        $this->login();
        $listing = $this->createListing();

        $this->patchJson('/api/rentals/'.$listing->id, ['pet_name' => 'Rex'])
            ->assertOk()->assertJsonPath('data.pet_name', 'Rex');

        $this->patchJson('/api/rentals/'.$listing->id, ['latitude' => 8])
            ->assertUnprocessable()->assertJsonValidationErrors('longitude');

        $this->patchJson('/api/rentals/'.$listing->id, ['latitude' => 8, 'longitude' => 80])
            ->assertOk();

        $this->patchJson('/api/rentals/'.$listing->id, ['status' => 'closed'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_other_users_cannot_mutate_a_listing_or_its_photos(): void
    {
        $this->login();
        $listing = $this->createListing();
        $photoId = $listing->photos()->first()->id;

        $this->login();
        $url = '/api/rentals/'.$listing->id;

        $this->getJson($url)->assertOk()->assertJsonPath('data.is_owner', false);
        $this->patchJson($url, ['pet_name' => 'Changed'])->assertForbidden();
        $this->deleteJson($url)->assertForbidden();
        $this->postJson($url.'/photos', ['photos' => [$this->photo()]])->assertForbidden();
        $this->deleteJson($url.'/photos/'.$photoId)->assertForbidden();
    }

    public function test_mine_search_does_not_leak_another_owners_listings(): void
    {
        $first = $this->login();
        $mine = $this->createListing(['pet_name' => 'Buddy']);

        $this->login();
        $this->createListing(['pet_name' => 'Buddy']);

        Sanctum::actingAs($first);
        $this->getJson('/api/rentals/mine?q=buddy')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_filters_search_and_pagination_work_together(): void
    {
        $this->login();
        $dog = $this->createListing(['pet_name' => 'Buddy', 'pet_type' => 'dog', 'price_per_day' => '25.50']);
        $cat = $this->createListing(['pet_name' => 'Milo', 'pet_type' => 'cat', 'price_per_day' => '50.00', 'location_name' => 'Colombo']);

        $this->getJson('/api/rentals?pet_type=dog&location=nittambuwa&min_price=20&max_price=30&q=buddy')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $dog->id);

        $this->getJson('/api/rentals?per_page=1&page=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.current_page', 2);

        $this->getJson('/api/rentals?per_page=1000')->assertUnprocessable();
    }

    public function test_deactivated_owners_listings_are_hidden(): void
    {
        $owner = $this->login();
        $listing = $this->createListing();
        $owner->update(['is_active' => false]);

        $this->login();
        $this->getJson('/api/rentals')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/rentals/'.$listing->id)->assertNotFound();
    }

    public function test_photo_limit_and_photo_removal_are_enforced(): void
    {
        $this->login();
        $listing = $this->createListing();
        $url = '/api/rentals/'.$listing->id.'/photos';

        $this->postJson($url, ['photos' => array_map(fn () => $this->photo(), range(1, 4))])
            ->assertOk()->assertJsonCount(5, 'data.photos');

        $this->postJson($url, ['photos' => [$this->photo()]])->assertUnprocessable();

        $photo = $listing->photos()->first();
        $this->deleteJson($url.'/'.$photo->id)->assertOk();
        Storage::disk('public')->assertMissing($photo->path);
        $this->assertDatabaseMissing('pet_rental_photos', ['id' => $photo->id]);
    }

    public function test_last_photo_cannot_be_removed_and_photo_ids_are_scoped(): void
    {
        $this->login();
        $one = $this->createListing();
        $two = $this->createListing();

        $url = '/api/rentals/'.$one->id.'/photos/';
        $this->deleteJson($url.$one->photos()->first()->id)->assertUnprocessable();
        $this->deleteJson($url.$two->photos()->first()->id)->assertNotFound();
    }

    public function test_delete_removes_listing_photo_rows_and_files(): void
    {
        $this->login();
        $listing = $this->createListing();
        $paths = $listing->photos()->pluck('path')->all();

        $this->deleteJson('/api/rentals/'.$listing->id)->assertOk();
        $this->assertDatabaseMissing('pet_rentals', ['id' => $listing->id]);
        $this->assertDatabaseMissing('pet_rental_photos', ['pet_rental_id' => $listing->id]);
        Storage::disk('public')->assertMissing($paths);
        $this->getJson('/api/rentals/'.$listing->id)->assertNotFound();
    }

    public function test_requester_can_create_a_rental_request(): void
    {
        $this->login();
        $listing = $this->createListing();

        $requester = $this->login();
        $response = $this->postJson('/api/rentals/'.$listing->id.'/requests', [
            'start_date' => now()->addDay()->format('Y-m-d'),
            'end_date' => now()->addDays(3)->format('Y-m-d'),
            'message' => 'I would like to book this pet for a weekend stay.',
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseHas('pet_rental_requests', [
            'pet_rental_id' => $listing->id,
            'requester_id' => $requester->id,
        ]);
    }

    public function test_requester_cannot_request_their_own_listing_and_invalid_dates_are_rejected(): void
    {
        $user = $this->login();
        $listing = $this->createListing();

        $this->postJson('/api/rentals/'.$listing->id.'/requests', [
            'start_date' => now()->addDay()->format('Y-m-d'),
            'end_date' => now()->addDays(2)->format('Y-m-d'),
        ])->assertUnprocessable()->assertJsonValidationErrors('pet_rental_id');

        $this->login();
        $this->postJson('/api/rentals/'.$listing->id.'/requests', [
            'start_date' => now()->addDays(3)->format('Y-m-d'),
            'end_date' => now()->addDay()->format('Y-m-d'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['end_date']);
    }

    public function test_owner_can_view_and_respond_to_requests(): void
    {
        $owner = $this->login();
        $listing = $this->createListing();

        $requester = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $request = PetRentalRequest::create([
            'pet_rental_id' => $listing->id,
            'requester_id' => $requester->id,
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'message' => 'Need for 3 days',
            'status' => 'pending',
        ]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/rentals/'.$listing->id.'/requests')->assertOk()->assertJsonPath('data.0.id', $request->id);

        $this->patchJson('/api/rental-requests/'.$request->id, ['status' => 'accepted'])
            ->assertOk()->assertJsonPath('data.status', 'accepted');

        $this->assertSame('active', $listing->fresh()->status);

        $this->patchJson('/api/rental-requests/'.$request->id, ['status' => 'rejected'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_requester_can_list_their_own_requests(): void
    {
        $owner = $this->login();
        $listing = $this->createListing();

        $requester = $this->login();
        $this->postJson('/api/rentals/'.$listing->id.'/requests', [
            'start_date' => now()->addDay()->format('Y-m-d'),
            'end_date' => now()->addDays(3)->format('Y-m-d'),
        ])->assertCreated();

        $this->getJson('/api/rental-requests/mine')->assertOk()->assertJsonPath('data.0.requester_id', $requester->id);
    }

    public function test_rental_request_fires_notification(): void
    {
        $owner = $this->login();
        $listing = $this->createListing();

        $requester = $this->login();
        $this->postJson('/api/rentals/'.$listing->id.'/requests', [
            'start_date' => now()->addDay()->format('Y-m-d'),
            'end_date' => now()->addDays(3)->format('Y-m-d'),
        ])->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'rental_request',
        ]);
    }

    public function test_request_dates_must_be_within_availability_window(): void
    {
        $this->login();
        $listing = $this->createListing([
            'available_from' => now()->addDays(5)->format('Y-m-d'),
            'available_to' => now()->addDays(10)->format('Y-m-d'),
        ]);

        $this->login();
        $this->postJson('/api/rentals/'.$listing->id.'/requests', [
            'start_date' => now()->addDays(3)->format('Y-m-d'),
            'end_date' => now()->addDays(4)->format('Y-m-d'),
        ])->assertUnprocessable()->assertJsonValidationErrors('start_date');
    }

    public function test_request_overlap_with_accepted_is_rejected(): void
    {
        $owner = $this->login();
        $listing = $this->createListing([
            'available_from' => now()->addDays(1)->format('Y-m-d'),
            'available_to' => now()->addDays(15)->format('Y-m-d'),
        ]);

        $requesterOne = $this->login();
        PetRentalRequest::create([
            'pet_rental_id' => $listing->id,
            'requester_id' => $requesterOne->id,
            'start_date' => now()->addDays(3)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'status' => 'accepted',
        ]);

        $requesterTwo = $this->login();
        $this->postJson('/api/rentals/'.$listing->id.'/requests', [
            'start_date' => now()->addDays(4)->format('Y-m-d'),
            'end_date' => now()->addDays(8)->format('Y-m-d'),
        ])->assertUnprocessable()->assertJsonValidationErrors('start_date');
    }

    public function test_rental_create_accepts_optional_fields(): void
    {
        $this->login();

        $response = $this->postJson('/api/rentals', $this->payload([
            'color' => 'Brown',
            'purpose' => 'Weekend companionship',
            'characteristics' => 'Friendly and energetic',
        ]))->assertCreated();

        $response->assertJsonPath('data.color', 'Brown');
        $response->assertJsonPath('data.purpose', 'Weekend companionship');
        $response->assertJsonPath('data.characteristics', 'Friendly and energetic');
    }
}