<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\PetService;
use App\Models\ServiceBooking;
use App\Models\User;
use App\Services\Reviews\BookingVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ServiceBookingReviewsTest extends TestCase
{
    use RefreshDatabase;

    private User $provider;
    private User $customer;
    private Business $business;
    private PetService $service;

    protected function setUp(): void
    {
        if (
            getenv('DB_CONNECTION') !== 'sqlite'
            || getenv('DB_DATABASE') !== ':memory:'
        ) {
            throw new \RuntimeException(
                'Use phpunit.reviews.xml with SQLite :memory:.'
            );
        }

        if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
            throw new \RuntimeException(
                'Run php artisan config:clear before testing.'
            );
        }

        parent::setUp();

        Carbon::setTestNow(
            Carbon::parse('2026-10-20T08:00:00Z')
        );

        config(['reviews.allow_unverified' => false]);
        $this->app->forgetInstance(BookingVerifier::class);

        $this->provider = $this->makeUser();
        $this->customer = $this->makeUser();

        $type = BusinessType::create([
            'name' => 'Groomer',
            'slug' => 'test-groomer',
        ]);

        $this->business = Business::create([
            'user_id' => $this->provider->id,
            'business_type_id' => $type->id,
            'name' => 'Test Business',
            'slug' => 'test-booking-business',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->service = new PetService([
            'business_id' => $this->business->id,
            'name' => 'Test Grooming',
            'slug' => 'test-booking-grooming',
            'service_type' => 'grooming',
            'description' => 'Test grooming service.',
            'price_from' => 2500,
            'currency' => 'LKR',
            'location_name' => 'Nittambuwa',
            'latitude' => 7.142,
            'longitude' => 80.096,
            'contact_phone' => '+94700000000',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $this->service->user()->associate($this->provider);
        $this->service->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
            'role' => 'user',
        ]);
    }

    private function completedBooking(): ServiceBooking
    {
        return ServiceBooking::create([
            'reference' => (string) Str::uuid(),
            'pet_service_id' => $this->service->id,
            'user_id' => $this->customer->id,
            'scheduled_at' => now()->subHours(2),
            'status' => 'completed',
            'completed_at' => now()->subHour(),
        ]);
    }

    private function reviewPayload(
        ServiceBooking $booking,
        array $overrides = [],
    ): array {
        return array_replace([
            'type' => 'service',
            'id' => $this->service->id,
            'booking_reference' => $booking->reference,
            'rating' => 5,
            'comment' => 'Good grooming service.',
        ], $overrides);
    }

    public function test_guest_cannot_create_booking(): void
    {
        $this->postJson(
            "/api/services/{$this->service->id}/bookings",
            ['scheduled_at' => now()->addDay()->toIso8601String()],
        )->assertUnauthorized();
    }

    public function test_customer_can_book_and_provider_can_complete(): void
    {
        Sanctum::actingAs($this->customer);

        $created = $this->postJson(
            "/api/services/{$this->service->id}/bookings",
            [
                'scheduled_at' => now()->addHour()->toIso8601String(),
                'message' => 'Please groom my dog.',
            ],
        )->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $bookingId = $created->json('data.id');

        $this->getJson('/api/service-bookings/mine')
            ->assertOk()
            ->assertJsonPath('total', 1);

        Sanctum::actingAs($this->provider);

        $this->getJson('/api/service-bookings/incoming')
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->patchJson("/api/service-bookings/{$bookingId}", [
            'status' => 'accepted',
            'provider_response' => 'Booking accepted.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->patchJson("/api/service-bookings/{$bookingId}", [
            'status' => 'completed',
        ])->assertUnprocessable();

        $this->travel(2)->hours();

        $this->patchJson("/api/service-bookings/{$bookingId}", [
            'status' => 'completed',
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $booking = ServiceBooking::findOrFail($bookingId);

        $this->assertNotNull($booking->completed_at);

        Sanctum::actingAs($this->customer);

        $this->postJson(
            '/api/reviews',
            $this->reviewPayload($booking),
        )->assertCreated()
            ->assertJsonPath('data.is_approved', false);
    }

    public function test_provider_cannot_book_own_service(): void
    {
        Sanctum::actingAs($this->provider);

        $this->postJson(
            "/api/services/{$this->service->id}/bookings",
            ['scheduled_at' => now()->addDay()->toIso8601String()],
        )->assertUnprocessable();

        $this->assertDatabaseCount('service_bookings', 0);
    }

    public function test_unapproved_service_cannot_be_booked(): void
    {
        $this->service->update(['status' => 'pending']);

        Sanctum::actingAs($this->customer);

        $this->postJson(
            "/api/services/{$this->service->id}/bookings",
            ['scheduled_at' => now()->addDay()->toIso8601String()],
        )->assertUnprocessable();
    }

    public function test_other_user_cannot_accept_or_review_booking(): void
    {
        $booking = $this->completedBooking();

        Sanctum::actingAs($this->makeUser());

        $this->patchJson(
            "/api/service-bookings/{$booking->id}",
            ['status' => 'accepted'],
        )->assertForbidden();

        $this->postJson(
            '/api/reviews',
            $this->reviewPayload($booking),
        )->assertUnprocessable()
            ->assertJsonPath('error', 'unverified_booking');
    }

    public function test_reference_cannot_review_another_service_or_business(): void
    {
        $booking = $this->completedBooking();

        $otherService = $this->service->replicate();
        $otherService->slug = 'another-test-service';
        $otherService->save();

        $otherBusiness = $this->business->replicate();
        $otherBusiness->slug = 'another-test-business';
        $otherBusiness->save();

        Sanctum::actingAs($this->customer);

        $this->postJson(
            '/api/reviews',
            $this->reviewPayload($booking, [
                'id' => $otherService->id,
            ]),
        )->assertUnprocessable()
            ->assertJsonPath('error', 'unverified_booking');

        $this->postJson(
            '/api/reviews',
            $this->reviewPayload($booking, [
                'type' => 'business',
                'id' => $otherBusiness->id,
            ]),
        )->assertUnprocessable()
            ->assertJsonPath('error', 'unverified_booking');

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_only_completed_bookings_allow_reviews(): void
    {
        $booking = $this->completedBooking();

        Sanctum::actingAs($this->customer);

        foreach (['pending', 'accepted', 'rejected', 'cancelled'] as $status) {
            $booking->update(['status' => $status]);

            $this->postJson(
                '/api/reviews',
                $this->reviewPayload($booking),
            )->assertUnprocessable()
                ->assertJsonPath('error', 'unverified_booking');
        }

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_completed_booking_allows_its_business_review(): void
    {
        $booking = $this->completedBooking();

        Sanctum::actingAs($this->customer);

        $payload = $this->reviewPayload($booking, [
            'type' => 'business',
            'id' => $this->business->id,
        ]);

        $this->postJson('/api/reviews', $payload)
            ->assertCreated()
            ->assertJsonPath('data.is_approved', false);

        $this->postJson('/api/reviews', $payload)
            ->assertUnprocessable();

        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_customer_can_cancel_but_cannot_complete_booking(): void
    {
        $booking = $this->completedBooking();
        $booking->update([
            'status' => 'accepted',
            'completed_at' => null,
        ]);

        Sanctum::actingAs($this->customer);

        $this->patchJson(
            "/api/service-bookings/{$booking->id}",
            ['status' => 'completed'],
        )->assertForbidden();

        $this->patchJson(
            "/api/service-bookings/{$booking->id}",
            ['status' => 'cancelled'],
        )->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertNotNull($booking->fresh()->cancelled_at);

        Sanctum::actingAs($this->provider);

        $this->patchJson(
            "/api/service-bookings/{$booking->id}",
            ['status' => 'completed'],
        )->assertUnprocessable();
    }
}