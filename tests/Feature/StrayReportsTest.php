<?php

namespace Tests\Feature;

use App\Models\StrayReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StrayReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Refuse to run database-resetting tests against a developer's database.
        if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('Use phpunit.stray-reports.xml with SQLite :memory:.');
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
            file_get_contents(base_path('tests/Fixtures/LostPets/pet.'.$extension)));
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'pet_name' => 'Buddy', 'pet_type' => 'dog', 'breed' => 'Labrador',
            'animal_condition' => 'unknown', 'gender' => 'male', 'color' => 'Brown', 'description' => 'Stray animal near the bus stand.',
            'spotted_at' => now()->subDay()->format('Y-m-d\TH:i:sP'),
            'location_name' => 'Nittambuwa', 'latitude' => '7.1420000',
            'longitude' => '80.0960000', 'contact_name' => 'Test Reporter',
            'contact_phone' => '0771234567', 'whatsapp_number' => '+94771234567',
            'photos' => [$this->photo()],
        ], $overrides);
    }

    private function createReport(array $overrides = []): StrayReport
    {
        $response = $this->postJson('/api/stray-reports', $this->payload($overrides))->assertCreated();

        return StrayReport::findOrFail($response->json('data.id'));
    }

    public function test_guests_cannot_read_or_create_reports(): void
    {
        $this->getJson('/api/stray-reports')->assertUnauthorized();
        $this->postJson('/api/stray-reports', [])->assertUnauthorized();
    }

    public function test_unverified_accounts_are_blocked(): void
    {
        $this->login(['email_verified_at' => null]);
        $this->postJson('/api/stray-reports', $this->payload())->assertForbidden();
    }

    public function test_existing_bearer_token_is_blocked_after_deactivation(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $token = $user->createToken('test')->plainTextToken;
        $user->update(['is_active' => false]);
        $this->withToken($token)->getJson('/api/stray-reports')->assertForbidden();
    }

    public function test_create_persists_owner_location_photos_and_utc_time(): void
    {
        $owner = $this->login();
        $report = $this->createReport(['spotted_at' => '2020-01-02T10:30:00+05:30']);

        $this->assertSame($owner->id, $report->user_id);
        $this->assertSame('open', $report->status);
        $this->assertSame('2020-01-02 05:00:00', $report->spotted_at->utc()->format('Y-m-d H:i:s'));
        Storage::disk('public')->assertExists($report->photos()->first()->path);
        $this->getJson('/api/stray-reports/'.$report->id)->assertOk()
            ->assertJsonPath('data.is_reporter', true)->assertJsonCount(1, 'data.photos')
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
        $this->postJson('/api/stray-reports', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['pet_type', 'description', 'spotted_at', 'photos']);
        $this->postJson('/api/stray-reports', $this->payload([
            'latitude' => 91, 'longitude' => 181, 'contact_phone' => 'not-a-number',
            'spotted_at' => now()->addDay()->format('Y-m-d\TH:i:sP'),
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'latitude', 'longitude', 'contact_phone', 'spotted_at',
        ]);
        $this->postJson('/api/stray-reports', $this->payload([
            'spotted_at' => '2020-01-02 10:30:00',
        ]))->assertUnprocessable()->assertJsonValidationErrors('spotted_at');
    }

    public function test_owner_and_status_cannot_be_injected_into_create(): void
    {
        $this->login();
        $this->postJson('/api/stray-reports', $this->payload([
            'user_id' => 987, 'status' => 'rescued',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['user_id', 'status']);
        $this->assertDatabaseCount('stray_reports', 0);
    }

    public function test_fake_image_content_and_disallowed_extensions_are_rejected(): void
    {
        $this->login();
        $bad = UploadedFile::fake()->createWithContent('pet.png', '<?php echo "bad";');
        $this->postJson('/api/stray-reports', $this->payload(['photos' => [$bad]]))
            ->assertUnprocessable()->assertJsonValidationErrors('photos.0');
        $bad = UploadedFile::fake()->createWithContent('pet.php',
            file_get_contents(base_path('tests/Fixtures/LostPets/pet.png')));
        $this->postJson('/api/stray-reports', $this->payload(['photos' => [$bad]]))
            ->assertUnprocessable()->assertJsonValidationErrors('photos.0');
    }

    public function test_oversized_photos_and_six_photo_creation_are_rejected(): void
    {
        $this->login();
        $this->postJson('/api/stray-reports', $this->payload(['photos' => [$this->photo()->size(5121)]]))
            ->assertUnprocessable()->assertJsonValidationErrors('photos.0');
        $this->postJson('/api/stray-reports', $this->payload([
            'photos' => array_map(fn () => $this->photo(), range(1, 6)),
        ]))->assertUnprocessable()->assertJsonValidationErrors('photos');
    }

    public function test_owner_can_update_fields_but_coordinates_must_be_paired(): void
    {
        $this->login();
        $report = $this->createReport();
        $this->patchJson('/api/stray-reports/'.$report->id, ['pet_name' => 'Bobby'])
            ->assertOk()->assertJsonPath('data.pet_name', 'Bobby');
        $this->patchJson('/api/stray-reports/'.$report->id, ['latitude' => 8])
            ->assertUnprocessable()->assertJsonValidationErrors('longitude');
        $this->patchJson('/api/stray-reports/'.$report->id, ['latitude' => 8, 'longitude' => 80])
            ->assertOk();
        $this->patchJson('/api/stray-reports/'.$report->id, ['status' => 'closed'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_other_users_cannot_mutate_a_report_or_its_photos(): void
    {
        $this->login();
        $report = $this->createReport();
        $photoId = $report->photos()->first()->id;
        $this->login();
        $url = '/api/stray-reports/'.$report->id;
        $this->getJson($url)->assertOk()->assertJsonPath('data.is_reporter', false);
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
        $url = '/api/stray-reports/'.$report->id.'/status';
        $this->patchJson($url, ['status' => 'rescued'])->assertOk();
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
        $this->getJson('/api/stray-reports/mine?q=buddy')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_filters_search_and_pagination_work_together(): void
    {
        $this->login();
        $dog = $this->createReport();
        $cat = $this->createReport(['pet_type' => 'cat', 'color' => 'White']);
        $this->patchJson('/api/stray-reports/'.$cat->id.'/status', ['status' => 'closed'])->assertOk();
        $this->getJson('/api/stray-reports?pet_type=dog&color=brown&location=nittambuwa&status=open&q=buddy')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $dog->id);
        $this->getJson('/api/stray-reports?per_page=1&page=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.current_page', 2);
        $this->getJson('/api/stray-reports?per_page=1000')->assertUnprocessable();
    }

    public function test_deactivated_owners_reports_are_hidden(): void
    {
        $owner = $this->login();
        $report = $this->createReport();
        $owner->update(['is_active' => false]);
        $this->login();
        $this->getJson('/api/stray-reports')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/stray-reports/'.$report->id)->assertNotFound();
    }

    public function test_photo_limit_and_photo_removal_are_enforced(): void
    {
        $this->login();
        $report = $this->createReport();
        $url = '/api/stray-reports/'.$report->id.'/photos';
        $this->postJson($url, ['photos' => array_map(fn () => $this->photo(), range(1, 4))])
            ->assertOk()->assertJsonCount(5, 'data.photos');
        $this->postJson($url, ['photos' => [$this->photo()]])->assertUnprocessable();
        $photo = $report->photos()->first();
        $this->deleteJson($url.'/'.$photo->id)->assertOk();
        Storage::disk('public')->assertMissing($photo->path);
        $this->assertDatabaseMissing('stray_photos', ['id' => $photo->id]);
    }

    public function test_last_photo_cannot_be_removed_and_photo_ids_are_scoped(): void
    {
        $this->login();
        $one = $this->createReport();
        $two = $this->createReport();
        $url = '/api/stray-reports/'.$one->id.'/photos/';
        $this->deleteJson($url.$one->photos()->first()->id)->assertUnprocessable();
        $this->deleteJson($url.$two->photos()->first()->id)->assertNotFound();
    }

    public function test_delete_removes_report_photo_rows_and_files(): void
    {
        $this->login();
        $report = $this->createReport();
        $paths = $report->photos()->pluck('path')->all();
        $this->deleteJson('/api/stray-reports/'.$report->id)->assertOk();
        $this->assertDatabaseMissing('stray_reports', ['id' => $report->id]);
        $this->assertDatabaseMissing('stray_photos', ['stray_report_id' => $report->id]);
        Storage::disk('public')->assertMissing($paths);
        $this->getJson('/api/stray-reports/'.$report->id)->assertNotFound();
    }

    public function test_animal_condition_is_required_validated_and_filterable(): void
    {
        $this->login();
        $payload = $this->payload();
        unset($payload['animal_condition']);
        $this->postJson('/api/stray-reports', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('animal_condition');
        $this->postJson('/api/stray-reports', $this->payload(['animal_condition' => 'invalid']))
            ->assertUnprocessable()->assertJsonValidationErrors('animal_condition');

        $injured = $this->createReport(['animal_condition' => 'injured']);
        $this->createReport(['animal_condition' => 'healthy']);
        $this->getJson('/api/stray-reports?animal_condition=injured')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $injured->id);
        $this->patchJson('/api/stray-reports/'.$injured->id, ['animal_condition' => 'sick'])
            ->assertOk()->assertJsonPath('data.animal_condition', 'sick');
        $this->getJson('/api/stray-reports?animal_condition=invalid')->assertUnprocessable();
    }

    public function test_unknown_name_breed_and_absent_whatsapp_are_allowed(): void
    {
        $this->login();
        $payload = $this->payload(['pet_name' => null, 'breed' => null, 'gender' => 'unknown']);
        unset($payload['whatsapp_number']);
        $this->postJson('/api/stray-reports', $payload)->assertCreated()
            ->assertJsonPath('data.pet_name', null)
            ->assertJsonPath('data.breed', null)
            ->assertJsonPath('data.whatsapp_number', null);
    }

    public function test_unverified_reporters_reports_are_hidden(): void
    {
        $reporter = $this->login();
        $report = $this->createReport();
        $reporter->forceFill(['email_verified_at' => null])->save();
        $this->login();
        $this->getJson('/api/stray-reports')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/stray-reports/'.$report->id)->assertNotFound();
    }

    public function test_open_stray_reports_appear_on_the_map_with_the_correct_details_url(): void
    {
        $this->login();
        $report = $this->createReport(['spotted_at' => '2020-01-02T10:30:00+05:30']);
        $base = '/api/map/reports?latitude=7.142&longitude=80.096';
        $this->getJson($base.'&kind=stray')->assertOk()
            ->assertJsonCount(1, 'features')
            ->assertJsonPath('features.0.id', 'stray:'.$report->id)
            ->assertJsonPath('features.0.properties.kind', 'stray')
            ->assertJsonPath('features.0.properties.occurred_at', '2020-01-02T05:00:00.000000Z')
            ->assertJsonPath('features.0.properties.details_url', url('/api/stray-reports/'.$report->id));
        $this->getJson($base)->assertOk()->assertJsonCount(1, 'features');
        $this->getJson($base.'&kind=lost')->assertOk()->assertJsonCount(0, 'features');
        $this->getJson($base.'&kind=found')->assertOk()->assertJsonCount(0, 'features');
    }

    public function test_rescued_reports_leave_the_open_map_and_can_be_reopened(): void
    {
        $this->login();
        $report = $this->createReport();
        $url = '/api/stray-reports/'.$report->id.'/status';
        $map = '/api/map/reports?latitude=7.142&longitude=80.096&kind=stray';
        $this->patchJson($url, ['status' => 'rescued'])->assertOk()
            ->assertJsonPath('data.status', 'rescued');
        $this->assertNotNull($report->fresh()->resolved_at);
        $this->getJson($map)->assertOk()->assertJsonCount(0, 'features');
        $this->getJson($map.'&status=rescued')->assertOk()->assertJsonCount(1, 'features');
        $this->patchJson($url, ['status' => 'open'])->assertOk()
            ->assertJsonPath('data.resolved_at', null);
        $this->getJson($map)->assertOk()->assertJsonCount(1, 'features');
        $this->patchJson($url, ['status' => 'reunited'])->assertUnprocessable();
    }

    public function test_stray_map_hides_reports_from_ineligible_accounts(): void
    {
        $reporter = $this->login();
        $this->createReport();
        $reporter->update(['is_active' => false]);
        $this->login();
        $this->getJson('/api/map/reports?latitude=7.142&longitude=80.096&kind=stray')
            ->assertOk()->assertJsonCount(0, 'features');
    }
}