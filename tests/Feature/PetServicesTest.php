<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\PetService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PetServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('Use phpunit.services.xml with SQLite :memory:.');
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
            'role' => 'user',
        ], $attributes))->refresh();
        Sanctum::actingAs($user);
        return $user;
    }

    private function admin(): User
    {
        return $this->login(['role' => 'admin']);
    }

    private function photo(string $ext = 'png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'pet.'.$ext,
            file_get_contents(base_path('tests/Fixtures/LostPets/pet.'.$ext))
        );
    }

    private function approvedBusinessFor(User $user): Business
    {
        $type = BusinessType::firstOrCreate(
            ['name' => 'Groomer'],
            ['name' => 'Groomer', 'slug' => 'groomer']
        );

        return Business::create([
            'user_id' => $user->id,
            'business_type_id' => $type->id,
            'name' => 'Test Groomer',
            'slug' => 'test-groomer-'.uniqid(),
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Happy Paws Grooming',
            'service_type' => 'grooming',
            'description' => 'Full grooming service for dogs and cats.',
            'short_description' => 'Premium grooming in Colombo',
            'price_from' => '2500.00',
            'price_to' => '10000.00',
            'currency' => 'LKR',
            'location_name' => 'Colombo 07',
            'latitude' => '6.9125',
            'longitude' => '79.8487',
            'contact_phone' => '+94771234567',
            'contact_email' => 'hello@happypaws.lk',
            'opening_hours' => ['mon_fri' => '9am-6pm', 'sat' => '9am-1pm'],
            'photos' => [$this->photo()],
        ], $overrides);
    }

    private function createService(User $provider, array $overrides = []): PetService
    {
        $this->approvedBusinessFor($provider);
        Sanctum::actingAs($provider);

        $response = $this->postJson('/api/services', $this->payload($overrides))
            ->assertCreated();

        return PetService::findOrFail($response->json('data.id'));
    }

    public function test_guests_cannot_read_or_create_services(): void
    {
        $this->getJson('/api/services')->assertUnauthorized();
        $this->postJson('/api/services', [])->assertUnauthorized();
    }

    public function test_unverified_accounts_are_blocked(): void
    {
        $this->login(['email_verified_at' => null]);
        $this->postJson('/api/services', $this->payload())->assertForbidden();
    }

    public function test_provider_without_approved_business_cannot_create_service(): void
    {
        $this->login();
        $this->postJson('/api/services', $this->payload())
            ->assertForbidden();
    }

    public function test_provider_with_approved_business_can_create_service(): void
    {
        $user = $this->login();
        $this->approvedBusinessFor($user);

        $response = $this->postJson('/api/services', $this->payload())->assertCreated();

        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.service_type', 'grooming');
        $this->assertDatabaseHas('pet_services', [
            'user_id' => $user->id,
            'status' => 'pending',
        ]);
    }

    public function test_public_browse_shows_only_approved_services(): void
    {
        $provider = $this->login();
        $pending = $this->createService($provider);
        $approved = $this->createService($provider);
        $approved->update(['status' => 'approved', 'approved_at' => now()]);

        // Browse as another user
        $this->login();
        $this->getJson('/api/services')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $approved->id);
    }

    public function test_owner_can_see_their_own_pending_listing(): void
    {
        $provider = $this->login();
        $pending = $this->createService($provider);

        $this->getJson("/api/services/{$pending->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $pending->id);
    }

    public function test_other_users_cannot_see_pending_listing(): void
    {
        $provider = $this->login();
        $pending = $this->createService($provider);

        $this->login();
        $this->getJson("/api/services/{$pending->id}")->assertNotFound();
    }

    public function test_owner_can_update_and_delete_own_listing(): void
    {
        $provider = $this->login();
        $service = $this->createService($provider);

        $this->patchJson("/api/services/{$service->id}", ['name' => 'New Name'])
            ->assertOk()->assertJsonPath('data.name', 'New Name');

        $this->deleteJson("/api/services/{$service->id}")->assertOk();
        $this->assertDatabaseMissing('pet_services', ['id' => $service->id]);
    }

    public function test_other_users_cannot_mutate_a_listing(): void
    {
        $provider = $this->login();
        $service = $this->createService($provider);

        $this->login();
        $this->patchJson("/api/services/{$service->id}", ['name' => 'Hacked'])
            ->assertForbidden();
        $this->deleteJson("/api/services/{$service->id}")->assertForbidden();
    }

    public function test_validation_rejects_bad_input(): void
    {
        $provider = $this->login();
        $this->approvedBusinessFor($provider);

        $this->postJson('/api/services', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'service_type', 'description', 'price_from', 'photos']);

        $this->postJson('/api/services', $this->payload([
            'service_type' => 'invalid_type',
            'price_from' => '-5',
            'latitude' => 91,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['service_type', 'price_from', 'latitude']);
    }

    public function test_owner_and_status_cannot_be_injected(): void
    {
        $provider = $this->login();
        $this->approvedBusinessFor($provider);

        $this->postJson('/api/services', $this->payload([
            'user_id' => 999,
            'business_id' => 999,
            'status' => 'approved',
            'slug' => 'hacked',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id', 'business_id', 'status', 'slug']);
    }

    public function test_photo_rules_are_enforced(): void
    {
        $provider = $this->login();
        $this->approvedBusinessFor($provider);

        $this->postJson('/api/services', $this->payload([
            'photos' => array_map(fn () => $this->photo(), range(1, 6)),
        ]))->assertUnprocessable()->assertJsonValidationErrors('photos');

        $bad = UploadedFile::fake()->createWithContent('bad.png', '<?php echo "x";');
        $this->postJson('/api/services', $this->payload(['photos' => [$bad]]))
            ->assertUnprocessable()->assertJsonValidationErrors('photos.0');
    }

    public function test_admin_can_approve_and_notification_is_fired(): void
    {
        $provider = $this->login();
        $service = $this->createService($provider);

        $this->admin();
        $this->patchJson("/api/admin/services/{$service->id}", ['status' => 'approved'])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $provider->id,
            'type' => 'business_approved',
        ]);
    }

    public function test_admin_can_reject_with_reason(): void
    {
        $provider = $this->login();
        $service = $this->createService($provider);

        $this->admin();
        $this->patchJson("/api/admin/services/{$service->id}", [
            'status' => 'rejected',
            'rejection_reason' => 'License expired',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('notifications', [
            'user_id' => $provider->id,
            'type' => 'business_rejected',
        ]);
    }

    public function test_admin_can_remove_listing(): void
    {
        $provider = $this->login();
        $service = $this->createService($provider);

        $this->admin();
        $this->deleteJson("/api/admin/services/{$service->id}")->assertOk();
        $this->assertDatabaseMissing('pet_services', ['id' => $service->id]);
    }

    public function test_non_admin_cannot_access_admin_endpoints(): void
    {
        $provider = $this->login();
        $service = $this->createService($provider);

        $this->login();
        $this->getJson('/api/admin/services')->assertForbidden();
        $this->patchJson("/api/admin/services/{$service->id}", ['status' => 'approved'])
            ->assertForbidden();
    }

    public function test_filters_and_pagination_work(): void
    {
        $provider = $this->login();
        $g = $this->createService($provider, ['name' => 'Groomer', 'service_type' => 'grooming', 'price_from' => '2000']);
        $v = $this->createService($provider, ['name' => 'Vet', 'service_type' => 'veterinary', 'price_from' => '5000']);

        $g->update(['status' => 'approved', 'approved_at' => now()]);
        $v->update(['status' => 'approved', 'approved_at' => now()]);

        $this->getJson('/api/services?service_type=grooming')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $g->id);

        $this->getJson('/api/services?max_price=3000')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $g->id);

        $this->getJson('/api/services?q=groomer')
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson('/api/services?per_page=1&page=2')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }
}