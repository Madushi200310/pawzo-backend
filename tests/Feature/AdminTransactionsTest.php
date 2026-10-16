<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\MarketplaceOrder;
use App\Models\PetSaleListing;
use App\Models\PetService;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ServiceBooking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $customer;
    private User $seller;
    private PetService $service;
    private Product $product;
    private PetSaleListing $pet;

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

        $this->admin = $this->user(['role' => 'admin']);
        $this->customer = $this->user();
        $this->seller = $this->user();

        $type = BusinessType::create([
            'name' => 'Test Business Type',
            'slug' => 'admin-transaction-type',
        ]);

        $business = Business::create([
            'user_id' => $this->seller->id,
            'business_type_id' => $type->id,
            'name' => 'Test Business',
            'slug' => 'admin-transaction-business',
            'status' => 'approved',
        ]);

        $this->service = new PetService([
            'business_id' => $business->id,
            'name' => 'Test Grooming',
            'slug' => 'admin-transaction-service',
            'service_type' => 'grooming',
            'description' => 'Test service.',
            'price_from' => 1000,
            'currency' => 'LKR',
            'location_name' => 'Nittambuwa',
            'latitude' => 7.142,
            'longitude' => 80.096,
            'contact_phone' => '+94700000000',
            'status' => 'approved',
        ]);

        $this->service->user()->associate($this->seller);
        $this->service->save();

        $category = ProductCategory::create([
            'name' => 'Test Category',
            'slug' => 'admin-transaction-category',
        ]);

        $this->product = Product::create([
            'user_id' => $this->seller->id,
            'product_category_id' => $category->id,
            'name' => 'Test Product',
            'price' => '1000.00',
            'quantity' => 3,
            'is_active' => true,
            'is_approved' => true,
        ]);

        $this->pet = PetSaleListing::create([
            'user_id' => $this->seller->id,
            'pet_type' => 'dog',
            'gender' => 'male',
            'price' => '15000.00',
            'status' => 'reserved',
            'is_active' => true,
            'is_approved' => true,
        ]);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_replace([
            'role' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function booking(string $status = 'pending'): ServiceBooking
    {
        return ServiceBooking::create([
            'reference' => (string) Str::uuid(),
            'user_id' => $this->customer->id,
            'pet_service_id' => $this->service->id,
            'scheduled_at' => now()->addDay(),
            'status' => $status,
        ]);
    }

    private function order(
        string $status = 'accepted',
        bool $pet = false,
    ): MarketplaceOrder {
        return MarketplaceOrder::create([
            'reference' => (string) Str::uuid(),
            'user_id' => $this->customer->id,
            'seller_id' => $this->seller->id,
            'product_id' => $pet ? null : $this->product->id,
            'pet_sale_listing_id' => $pet ? $this->pet->id : null,
            'quantity' => $pet ? 1 : 2,
            'unit_price' => $pet ? '15000.00' : '1000.00',
            'total_price' => $pet ? '15000.00' : '2000.00',
            'currency' => 'LKR',
            'status' => $status,
        ]);
    }

    public function test_guests_and_non_admins_are_blocked(): void
    {
        $booking = $this->booking();
        $order = $this->order();

        $paths = [
            '/api/admin/service-bookings',
            '/api/admin/marketplace-orders',
            "/api/admin/service-bookings/{$booking->id}",
            "/api/admin/marketplace-orders/{$order->id}",
        ];

        foreach ($paths as $path) {
            $this->getJson($path)->assertUnauthorized();
        }

        Sanctum::actingAs($this->customer);

        foreach ($paths as $path) {
            $this->getJson($path)->assertForbidden();
        }

        $this->patchJson(
            "/api/admin/service-bookings/{$booking->id}/cancel",
            ['reason' => 'Test cancellation'],
        )->assertForbidden();

        $this->patchJson(
            "/api/admin/marketplace-orders/{$order->id}/cancel",
            ['reason' => 'Test cancellation'],
        )->assertForbidden();
    }

    public function test_inactive_and_unverified_admins_are_blocked(): void
    {
        foreach ([
            ['is_active' => false],
            ['email_verified_at' => null],
        ] as $attributes) {
            Sanctum::actingAs(
                $this->user(array_merge(['role' => 'admin'], $attributes))
            );

            $this->getJson('/api/admin/service-bookings')
                ->assertForbidden();

            $this->getJson('/api/admin/marketplace-orders')
                ->assertForbidden();
        }
    }

    public function test_admin_can_filter_lists_and_view_details(): void
    {
        $booking = $this->booking('accepted');
        $this->booking('pending');
        $order = $this->order();

        Sanctum::actingAs($this->admin);

        $bookingQuery = http_build_query([
            'status' => 'accepted',
            'reference' => $booking->reference,
            'user_id' => $this->customer->id,
            'pet_service_id' => $this->service->id,
        ]);

        $this->getJson('/api/admin/service-bookings?'.$bookingQuery)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $booking->id);

        $orderQuery = http_build_query([
            'status' => 'accepted',
            'seller_id' => $this->seller->id,
            'reference' => $order->reference,
            'type' => 'product',
        ]);

        $this->getJson('/api/admin/marketplace-orders?'.$orderQuery)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $order->id);

        $this->getJson("/api/admin/service-bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.user.id', $this->customer->id);

        $this->getJson("/api/admin/marketplace-orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.seller.id', $this->seller->id);

        $this->getJson('/api/admin/service-bookings/999999')
            ->assertNotFound();
    }

    public function test_invalid_filters_and_missing_reasons_are_blocked(): void
    {
        $booking = $this->booking();
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/service-bookings?status=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->getJson('/api/admin/marketplace-orders?per_page=100')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');

        $this->getJson('/api/admin/service-bookings?type=product')
            ->assertUnprocessable();

        $this->patchJson(
            "/api/admin/service-bookings/{$booking->id}/cancel",
            ['reason' => '   '],
        )->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertSame('pending', $booking->fresh()->status);
    }

    public function test_booking_cancellation_records_admin_and_reason(): void
    {
        $booking = $this->booking('accepted');
        Sanctum::actingAs($this->admin);

        $url = "/api/admin/service-bookings/{$booking->id}/cancel";

        $this->patchJson($url, ['reason' => 'Provider complaint.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath(
                'data.cancelled_by_admin_id',
                $this->admin->id,
            )
            ->assertJsonPath(
                'data.admin_cancellation_reason',
                'Provider complaint.',
            );

        $cancelledAt = $booking->fresh()->cancelled_at->toISOString();

        $this->patchJson($url, ['reason' => 'Repeated request.'])
            ->assertOk()
            ->assertJsonPath(
                'data.admin_cancellation_reason',
                'Provider complaint.',
            );

        $this->assertSame(
            $cancelledAt,
            $booking->fresh()->cancelled_at->toISOString(),
        );
    }

    public function test_accepted_product_stock_is_restored_only_once(): void
    {
        // Fixture represents an accepted order with two units reserved.
        $order = $this->order();
        Sanctum::actingAs($this->admin);

        $url = "/api/admin/marketplace-orders/{$order->id}/cancel";

        $this->patchJson($url, ['reason' => 'Seller complaint.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(5, $this->product->fresh()->quantity);

        $this->patchJson($url, ['reason' => 'Repeated request.'])
            ->assertOk();

        $this->assertSame(5, $this->product->fresh()->quantity);

        $pending = $this->order('pending');

        $this->patchJson(
            "/api/admin/marketplace-orders/{$pending->id}/cancel",
            ['reason' => 'Cancel pending request.'],
        )->assertOk();

        $this->assertSame(5, $this->product->fresh()->quantity);
    }

    public function test_pet_reservation_is_released_on_admin_cancellation(): void
    {
        $order = $this->order('accepted', true);
        Sanctum::actingAs($this->admin);

        $this->patchJson(
            "/api/admin/marketplace-orders/{$order->id}/cancel",
            ['reason' => 'Listing complaint.'],
        )->assertOk();

        $this->assertSame('available', $this->pet->fresh()->status);
    }

    public function test_invalid_pet_state_rolls_back_cancellation(): void
    {
        $order = $this->order('accepted', true);
        $this->pet->update(['status' => 'sold']);

        Sanctum::actingAs($this->admin);

        $this->patchJson(
            "/api/admin/marketplace-orders/{$order->id}/cancel",
            ['reason' => 'Investigate listing.'],
        )->assertUnprocessable();

        $this->assertSame('accepted', $order->fresh()->status);
        $this->assertNull($order->fresh()->cancelled_at);
        $this->assertNull($order->fresh()->cancelled_by_admin_id);
        $this->assertSame('sold', $this->pet->fresh()->status);
    }

    public function test_completed_and_rejected_records_cannot_be_cancelled(): void
    {
        Sanctum::actingAs($this->admin);

        foreach (['completed', 'rejected'] as $status) {
            $booking = $this->booking($status);
            $order = $this->order($status);

            $this->patchJson(
                "/api/admin/service-bookings/{$booking->id}/cancel",
                ['reason' => 'Test cancellation.'],
            )->assertUnprocessable();

            $this->patchJson(
                "/api/admin/marketplace-orders/{$order->id}/cancel",
                ['reason' => 'Test cancellation.'],
            )->assertUnprocessable();

            $this->assertSame($status, $booking->fresh()->status);
            $this->assertSame($status, $order->fresh()->status);
        }
    }
}