<?php

namespace Tests\Feature;

use App\Models\FoundPetReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FoundPetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Refuse to run database-resetting tests against a developer's database.
        if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('Use phpunit.found-pets.xml with SQLite :memory:.');
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
            'is_active' => true, 'email_verified_at' => now(),
        ], $attributes))->refresh();
        Sanctum::actingAs($user);

        return $user;
    }

    private function photo(string $extension = 'png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('pet.'.$extension,
            file_get_contents(base_path('tests/Fixtures/FoundPets/pet.'.$extension)));
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'pet_name' => 'Buddy', 'pet_type' => 'dog', 'breed' => 'Labrador',
            'gender' => 'male', 'color' => 'Brown', 'description' => 'Found near the bus stand.',
            'found_at' => now()->subDay()->format('Y-m-d\TH:i:sP'),
            'location_name' => 'Nittambuwa', 'latitude' => '7.1420000',
            'longitude' => '80.0960000', 'contact_name' => 'Test Finder',
            'contact_phone' => '0771234567', 'whatsapp_number' => '+94771234567',
            'photos' => [$this->photo()],
        ], $overrides);
    }

    private function createReport(array $overrides = []): FoundPetReport
    {
        $response = $this->postJson('/api/found-pets', $this->payload($overrides))->assertCreated();

        return FoundPetReport::findOrFail($response->json('data.id'));
    }

    public function test_guests_cannot_read_or_create_reports(): void
    {
        $this->getJson('/api/found-pets')->assertUnauthorized();
        $this->postJson('/api/found-pets', [])->assertUnauthorized();
    }

    public function test_unverified_accounts_are_blocked(): void
    {
        $this->login(['email_verified_at' => null]);
        $this->postJson('/api/found-pets', $this->payload())->assertForbidden();
    }

    public function test_existing_bearer_token_is_blocked_after_deactivation(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('test')->plainTextToken;
        $user->update(['is_active' => false]);
        $this->withToken($token)->getJson('/api/found-pets')->assertForbidden();
    }

    public function test_create_persists_owner_location_photos_and_utc_time(): void
    {
        $owner = $this->login();
        $report = $this->createReport(['found_at' => '2020-01-02T10:30:00+05:30']);

        $this->assertSame($owner->id, $report->user_id);
        $this->assertSame('open', $report->status);
        $this->assertSame('2020-01-02 05:00:00', $report->found_at->utc()->format('Y-m-d H:i:s'));
        Storage::disk('public')->assertExists($report->photos()->first()->path);
        $this->getJson('/api/found-pets/'.$report->id)->assertOk()
            ->assertJsonPath('data.is_owner', true)->assertJsonCount(1, 'data.photos')
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.user');
    }

    public function test_all_four_requested_image_extensions_are_accepted(): void
    {
        $this->login();
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $extension) {
            $this->createReport(['photos' => [$this->photo($extension)]]);
        }
    }

    public function test_required_fields_coordinates_phone_and_future_date_are_validated(): void
    {
        $this->login();
        $this->postJson('/api/found-pets', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['pet_type', 'description', 'found_at', 'photos']);
        $this->postJson('/api/found-pets', $this->payload([
            'latitude' => 91, 'longitude' => 181, 'contact_phone' => 'not-a-number',
            'found_at' => now()->addDay()->format('Y-m-d\TH:i:sP'),
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'latitude', 'longitude', 'contact_phone', 'found_at',
        ]);
        $this->postJson('/api/found-pets', $this->payload([
            'found_at' => '2020-01-02 10:30:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors('found_at');
    }

    public function test_owner_and_status_cannot_be_injected_into_create(): void
    {
        $this->login();
        $this->postJson('/api/found-pets', $this->payload([
            'user_id' => 987, 'status' => 'reunited',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'status']);
        $this->assertDatabaseCount('found_pet_reports', 0);
    }

    public function test_fake_image_content_and_disallowed_extensions_are_rejected(): void
    {
        $this->login();
        $bad = UploadedFile::fake()->createWithContent('pet.png', '<?php echo "bad";');
        $this->postJson('/api/found-pets', $this->payload(['photos' => [$bad]]))
            ->assertUnprocessable()->assertJsonValidationErrors('photos.0');
        $bad = UploadedFile::fake()->createWithContent('pet.php',
            file_get_contents(base_path('tests/Fixtures/FoundPets/pet.png')));
        $this->postJson('/api/found-pets', $this->payload(['photos' => [$bad]]))
            ->assertUnprocessable()->assertJsonValidationErrors('photos.0');
    }

    public function test_oversized_photos_and_six_photo_creation_are_rejected(): void
    {
        $this->login();
        $this->postJson('/api/found-pets', $this->payload(['photos' => [$this->photo()->size(5121)]]))
            ->assertUnprocessable()->assertJsonValidationErrors('photos.0');
        $this->postJson('/api/found-pets', $this->payload([
            'photos' => array_map(fn () => $this->photo(), range(1, 6)),
        ]))->assertUnprocessable()->assertJsonValidationErrors('photos');
    }

    public function test_owner_can_update_fields_but_coordinates_must_be_paired(): void
    {
        $this->login();
        $report = $this->createReport();
        $this->patchJson('/api/found-pets/'.$report->id, ['pet_name' => 'Bobby'])
            ->assertOk()->assertJsonPath('data.pet_name', 'Bobby');
        $this->patchJson('/api/found-pets/'.$report->id, ['latitude' => 8])
            ->assertUnprocessable()->assertJsonValidationErrors('longitude');
        $this->patchJson('/api/found-pets/'.$report->id, ['latitude' => 8, 'longitude' => 80])
            ->assertOk();
        $this->patchJson('/api/found-pets/'.$report->id, ['status' => 'closed'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_other_users_cannot_mutate_a_report_or_its_photos(): void
    {
        $this->login();
        $report = $this->createReport();
        $photoId = $report->photos()->first()->id;
        $this->login();
        $url = '/api/found-pets/'.$report->id;
        $this->getJson($url)->assertOk()->assertJsonPath('data.is_owner', false);
        $this->patchJson($url, ['pet_name' => 'Changed'])->assertForbidden();
        $this->deleteJson($url)->assertForbidden();
        $this->patchJson($url.'/status', ['status' => 'closed'])->assertForbidden();
        $this->postJson($url.'/photos', ['photos' => [$this->photo()]])->assertForbidden();
        $this->deleteJson($url.'/photos/'.$photoId)->assertForbidden();
    }

    public function test_status_can_resolve_and_reopen_a_report(): void
    {
        $this->login();
        $report = $this->createReport();
        $url = '/api/found-pets/'.$report->id.'/status';
        $this->patchJson($url, ['status' => 'reunited'])->assertOk();
        $this->assertNotNull($report->fresh()->resolved_at);
        $this->patchJson($url, ['status' => 'open'])->assertOk()->assertJsonPath('data.resolved_at', null);
        $this->patchJson($url, ['status' => 'invalid'])->assertUnprocessable();
    }

    public function test_mine_search_does_not_leak_another_owners_reports(): void
    {
        $first = $this->login();
        $mine = $this->createReport(['pet_name' => 'Buddy']);
        $this->login();
        $this->createReport(['pet_name' => 'Buddy']);
        Sanctum::actingAs($first);
        $this->getJson('/api/found-pets/mine?q=buddy')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_filters_search_and_pagination_work_together(): void
    {
        $this->login();
        $dog = $this->createReport();
        $cat = $this->createReport(['pet_type' => 'cat', 'color' => 'White']);
        $this->patchJson('/api/found-pets/'.$cat->id.'/status', ['status' => 'closed'])->assertOk();
        $this->getJson('/api/found-pets?pet_type=dog&color=brown&location=nittambuwa&status=open&q=buddy')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $dog->id);
        $this->getJson('/api/found-pets?per_page=1&page=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.current_page', 2);
        $this->getJson('/api/found-pets?per_page=1000')->assertUnprocessable();
    }

    public function test_deactivated_owners_reports_are_hidden(): void
    {
        $owner = $this->login();
        $report = $this->createReport();
        $owner->update(['is_active' => false]);
        $this->login();
        $this->getJson('/api/found-pets')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/found-pets/'.$report->id)->assertNotFound();
    }

    public function test_photo_limit_and_photo_removal_are_enforced(): void
    {
        $this->login();
        $report = $this->createReport();
        $url = '/api/found-pets/'.$report->id.'/photos';
        $this->postJson($url, ['photos' => array_map(fn () => $this->photo(), range(1, 4))])
            ->assertOk()->assertJsonCount(5, 'data.photos');
        $this->postJson($url, ['photos' => [$this->photo()]])->assertUnprocessable();
        $photo = $report->photos()->first();
        $this->deleteJson($url.'/'.$photo->id)->assertOk();
        Storage::disk('public')->assertMissing($photo->path);
        $this->assertDatabaseMissing('found_pet_photos', ['id' => $photo->id]);
    }

    public function test_last_photo_cannot_be_removed_and_photo_ids_are_scoped(): void
    {
        $this->login();
        $one = $this->createReport();
        $two = $this->createReport();
        $url = '/api/found-pets/'.$one->id.'/photos/';
        $this->deleteJson($url.$one->photos()->first()->id)->assertUnprocessable();
        $this->deleteJson($url.$two->photos()->first()->id)->assertNotFound();
    }

    public function test_delete_removes_report_photo_rows_and_files(): void
    {
        $this->login();
        $report = $this->createReport();
        $paths = $report->photos()->pluck('path')->all();
        $this->deleteJson('/api/found-pets/'.$report->id)->assertOk();
        $this->assertDatabaseMissing('found_pet_reports', ['id' => $report->id]);
        $this->assertDatabaseMissing('found_pet_photos', ['found_pet_report_id' => $report->id]);
        Storage::disk('public')->assertMissing($paths);
        $this->getJson('/api/found-pets/'.$report->id)->assertNotFound();
    }
}