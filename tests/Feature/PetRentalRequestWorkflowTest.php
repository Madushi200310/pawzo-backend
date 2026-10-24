<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\PetRental;
use App\Models\PetRentalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PetRentalRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (
            getenv('DB_CONNECTION') !== 'sqlite'
            || getenv('DB_DATABASE') !== ':memory:'
        ) {
            throw new \RuntimeException(
                'Use phpunit.rentals.xml with SQLite :memory:.'
            );
        }

        if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
            throw new \RuntimeException(
                'Run php artisan config:clear first.'
            );
        }

        parent::setUp();

        Storage::fake('public');
    }

    private function user(): User
    {
        return User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function listing(User $owner): PetRental
    {
        Sanctum::actingAs($owner);

        $response = $this->postJson('/api/rentals', [
            'pet_name' => 'Buddy',
            'pet_type' => 'dog',
            'breed' => 'Labrador',
            'gender' => 'male',
            'age' => '2 years',
            'price_per_day' => '25.00',
            'location_name' => 'Nittambuwa',
            'latitude' => 7.142,
            'longitude' => 80.096,
            'description' => 'Available for photoshoots.',
            'available_from' => now()->addDay()->toDateString(),
            'available_to' => now()->addDays(20)->toDateString(),
            'photos' => [
                UploadedFile::fake()->createWithContent(
                    'pet.png',
                    file_get_contents(
                        base_path('tests/Fixtures/LostPets/pet.png')
                    )
                ),
            ],
        ])->assertCreated();

        return PetRental::findOrFail($response->json('data.id'));
    }

    private function rentalRequest(
        PetRental $rental,
        User $requester,
        int $start = 2,
        int $end = 4,
        string $status = 'pending',
    ): PetRentalRequest {
        return PetRentalRequest::create([
            'pet_rental_id' => $rental->id,
            'requester_id' => $requester->id,
            'start_date' => now()->addDays($start)->toDateString(),
            'end_date' => now()->addDays($end)->toDateString(),
            'message' => 'Original requester message',
            'status' => $status,
        ]);
    }

    public function test_acceptance_notifies_requester_without_overwriting_original_message(): void
    {
        $owner = $this->user();
        $requester = $this->user();
        $rental = $this->listing($owner);
        $request = $this->rentalRequest($rental, $requester);

        Sanctum::actingAs($owner);

        $this->patchJson('/api/rental-requests/'.$request->id, [
            'status' => 'accepted',
            'owner_response' => 'Your request is approved.',
        ])->assertOk();

        $request->refresh();

        $this->assertSame('accepted', $request->status);
        $this->assertSame(
            'Original requester message',
            $request->message
        );
        $this->assertSame(
            'Your request is approved.',
            $request->owner_response
        );

        $notification = Notification::query()
            ->where('user_id', $requester->id)
            ->sole();

        $this->assertSame(
            'rental_request_accepted',
            $notification->data['event']
        );
        $this->assertSame(
            (int) $request->id,
            $notification->data['rental_request_id']
        );

        // Repeating acceptance must not create a second alert.
        $this->patchJson('/api/rental-requests/'.$request->id, [
            'status' => 'accepted',
        ])->assertOk();

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_acceptance_rejects_only_overlapping_pending_requests(): void
    {
        $owner = $this->user();
        $rental = $this->listing($owner);

        $accepted = $this->rentalRequest(
            $rental,
            $this->user(),
            2,
            4,
        );

        $overlapping = $this->rentalRequest(
            $rental,
            $this->user(),
            4,
            6,
        );

        $separate = $this->rentalRequest(
            $rental,
            $this->user(),
            7,
            9,
        );

        Sanctum::actingAs($owner);

        $this->patchJson('/api/rental-requests/'.$accepted->id, [
            'status' => 'accepted',
        ])->assertOk();

        $this->assertSame('accepted', $accepted->fresh()->status);
        $this->assertSame('rejected', $overlapping->fresh()->status);
        $this->assertSame('pending', $separate->fresh()->status);

        $notification = Notification::query()
            ->where('user_id', $overlapping->requester_id)
            ->sole();

        $this->assertSame(
            'rental_request_rejected',
            $notification->data['event']
        );

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $separate->requester_id,
        ]);
    }

    public function test_requester_can_cancel_pending_and_accepted_requests_and_notify_owner(): void
    {
        $owner = $this->user();
        $requester = $this->user();
        $rental = $this->listing($owner);

        foreach (['pending', 'accepted'] as $status) {
            $request = $this->rentalRequest(
                $rental,
                $requester,
                status: $status,
            );

            Sanctum::actingAs($requester);

            $this->patchJson('/api/rental-requests/'.$request->id, [
                'status' => 'cancelled',
                'message' => 'My plans changed.',
            ])
                ->assertOk()
                ->assertJsonPath('data.status', 'cancelled');

            $notification = Notification::query()
                ->where('user_id', $owner->id)
                ->latest('id')
                ->firstOrFail();

            $this->assertSame(
                'rental_request_cancelled',
                $notification->data['event']
            );
            $this->assertSame(
                (int) $request->id,
                $notification->data['rental_request_id']
            );

            // Cancellation retries must be idempotent.
            $before = Notification::count();

            $this->patchJson('/api/rental-requests/'.$request->id, [
                'status' => 'cancelled',
            ])->assertOk();

            $this->assertSame($before, Notification::count());
        }
    }

    public function test_only_owner_can_respond_and_only_requester_can_cancel(): void
    {
        $owner = $this->user();
        $requester = $this->user();
        $stranger = $this->user();

        $rental = $this->listing($owner);
        $request = $this->rentalRequest($rental, $requester);
        $url = '/api/rental-requests/'.$request->id;

        Sanctum::actingAs($stranger);

        foreach (['accepted', 'rejected', 'cancelled'] as $status) {
            $this->patchJson($url, ['status' => $status])
                ->assertForbidden();
        }

        Sanctum::actingAs($requester);

        foreach (['accepted', 'rejected'] as $status) {
            $this->patchJson($url, ['status' => $status])
                ->assertForbidden();
        }

        Sanctum::actingAs($owner);

        $this->patchJson($url, ['status' => 'cancelled'])
            ->assertForbidden();

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertDatabaseCount('notifications', 0);
    }
}