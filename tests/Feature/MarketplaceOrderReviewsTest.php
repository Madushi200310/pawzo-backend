<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\MarketplaceOrder;
use App\Models\PetSaleListing;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Reviews\BookingVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MarketplaceOrderReviewsTest extends TestCase
{
    use RefreshDatabase;

    private User $seller;
    private User $customer;
    private Business $business;
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

        config(['reviews.allow_unverified' => false]);
        $this->app->forgetInstance(BookingVerifier::class);

        $this->seller = $this->makeUser();
        $this->customer = $this->makeUser();

        $type = BusinessType::create([
            'name' => 'Test Seller',
            'slug' => 'order-test-seller',
        ]);

        $this->business = Business::create([
            'user_id' => $this->seller->id,
            'business_type_id' => $type->id,
            'name' => 'Order Test Business',
            'slug' => 'order-test-business',
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        $category = ProductCategory::create([
            'name' => 'Test Food',
            'slug' => 'order-test-food',
        ]);

        $this->product = Product::create([
            'user_id' => $this->seller->id,
            'product_category_id' => $category->id,
            'name' => 'Test Pet Food',
            'slug' => 'order-test-pet-food',
            'price' => '1250.25',
            'quantity' => 5,
            'is_approved' => true,
            'is_active' => true,
        ]);

        $this->pet = PetSaleListing::create([
            'user_id' => $this->seller->id,
            'pet_type' => 'dog',
            'gender' => 'male',
            'price' => '15000.00',
            'status' => 'available',
            'is_approved' => true,
            'is_active' => true,
        ]);
    }

    private function makeUser(): User
    {
        return User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
            'role' => 'user',
        ]);
    }

    private function order(
        string $type = 'product',
        int $quantity = 2,
    ): MarketplaceOrder {
        Sanctum::actingAs($this->customer);

        $id = $type === 'product'
            ? $this->product->id
            : $this->pet->id;

        $response = $this->postJson('/api/marketplace-orders', [
            'type' => $type,
            'id' => $id,
            'quantity' => $quantity,
            'message' => 'Local integration test.',
        ])->assertCreated();

        return MarketplaceOrder::findOrFail(
            $response->json('data.id')
        );
    }

    private function respond(
        MarketplaceOrder $order,
        string $status,
    ) {
        return $this->patchJson(
            "/api/marketplace-orders/{$order->id}",
            ['status' => $status],
        );
    }

    private function review(
        MarketplaceOrder $order,
        array $overrides = [],
    ) {
        return $this->postJson('/api/reviews', array_replace([
            'type' => $order->product_id !== null
                ? 'product'
                : 'pet-sale',
            'id' => $order->product_id ?? $order->pet_sale_listing_id,
            'booking_reference' => $order->reference,
            'rating' => 5,
            'comment' => 'Good purchase.',
        ], $overrides));
    }

    public function test_guest_cannot_order(): void
    {
        $this->postJson('/api/marketplace-orders', [
            'type' => 'product',
            'id' => $this->product->id,
            'quantity' => 1,
        ])->assertUnauthorized();
    }

    public function test_product_order_uses_server_price_and_completed_review(): void
    {
        $order = $this->order();

        $this->assertSame('1250.25', $order->unit_price);
        $this->assertSame('2500.50', $order->total_price);
        $this->assertSame(5, $this->product->fresh()->quantity);

        $this->getJson('/api/marketplace-orders/mine')
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->review($order)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'unverified_booking');

        Sanctum::actingAs($this->seller);

        $this->getJson('/api/marketplace-orders/incoming')
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->respond($order, 'accepted')
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->assertSame(3, $this->product->fresh()->quantity);

        // Retrying acceptance must not deduct stock again.
        $this->respond($order, 'accepted')->assertOk();
        $this->assertSame(3, $this->product->fresh()->quantity);

        $this->respond($order, 'completed')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertNotNull($order->fresh()->completed_at);

        Sanctum::actingAs($this->customer);

        $this->review($order)
            ->assertCreated()
            ->assertJsonPath('data.is_approved', false);

        $this->review($order)->assertUnprocessable();
        $this->assertDatabaseCount('reviews', 1);
    }

    public function test_cancellation_restores_stock_once_and_blocks_review(): void
    {
        $order = $this->order();

        Sanctum::actingAs($this->seller);
        $this->respond($order, 'accepted')->assertOk();

        $this->assertSame(3, $this->product->fresh()->quantity);

        Sanctum::actingAs($this->customer);

        $this->respond($order, 'cancelled')->assertOk();
        $this->assertSame(5, $this->product->fresh()->quantity);

        $this->respond($order, 'cancelled')->assertOk();
        $this->assertSame(5, $this->product->fresh()->quantity);

        $this->review($order)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'unverified_booking');

        Sanctum::actingAs($this->seller);

        $this->respond($order, 'completed')
            ->assertUnprocessable();
    }

    public function test_acceptance_rechecks_stock_after_other_orders(): void
    {
        $first = $this->order('product', 4);
        $second = $this->order('product', 4);

        Sanctum::actingAs($this->seller);

        $this->respond($first, 'accepted')->assertOk();
        $this->respond($second, 'accepted')->assertUnprocessable();

        $this->assertSame(1, $this->product->fresh()->quantity);
        $this->assertSame('pending', $second->fresh()->status);
    }

    public function test_customer_and_other_users_cannot_act_as_seller(): void
    {
        $order = $this->order();

        $this->respond($order, 'accepted')->assertForbidden();
        $this->respond($order, 'completed')->assertForbidden();

        Sanctum::actingAs($this->makeUser());

        $this->respond($order, 'accepted')->assertForbidden();
        $this->respond($order, 'cancelled')->assertForbidden();

        $this->getJson('/api/marketplace-orders/mine')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_completed_reference_is_bound_to_customer_and_item(): void
    {
        $order = $this->order();

        Sanctum::actingAs($this->seller);
        $this->respond($order, 'accepted')->assertOk();
        $this->respond($order, 'completed')->assertOk();

        $otherProduct = $this->product->replicate();
        $otherProduct->slug = 'another-order-test-product';
        $otherProduct->save();

        Sanctum::actingAs($this->customer);

        $this->review($order, ['id' => $otherProduct->id])
            ->assertUnprocessable()
            ->assertJsonPath('error', 'unverified_booking');

        $this->review($order, [
            'type' => 'pet-sale',
            'id' => $this->pet->id,
        ])->assertUnprocessable()
            ->assertJsonPath('error', 'unverified_booking');

        Sanctum::actingAs($this->makeUser());

        $this->review($order)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'unverified_booking');

        $this->assertDatabaseCount('reviews', 0);
    }

    public function test_pet_order_reserves_then_marks_sold_and_allows_review(): void
    {
        $order = $this->order('pet-sale', 1);

        Sanctum::actingAs($this->seller);

        $this->respond($order, 'accepted')->assertOk();
        $this->assertSame('reserved', $this->pet->fresh()->status);

        $this->respond($order, 'completed')->assertOk();
        $this->assertSame('sold', $this->pet->fresh()->status);

        Sanctum::actingAs($this->customer);
        $this->review($order)->assertCreated();
    }

    public function test_pet_order_cancellation_releases_reservation(): void
    {
        $order = $this->order('pet-sale', 1);

        Sanctum::actingAs($this->seller);
        $this->respond($order, 'accepted')->assertOk();

        Sanctum::actingAs($this->customer);
        $this->respond($order, 'cancelled')->assertOk();

        $this->assertSame('available', $this->pet->fresh()->status);
    }

    public function test_invalid_listing_quantity_and_client_prices_are_blocked(): void
    {
        Sanctum::actingAs($this->seller);

        $this->postJson('/api/marketplace-orders', [
            'type' => 'product',
            'id' => $this->product->id,
            'quantity' => 1,
        ])->assertUnprocessable();

        Sanctum::actingAs($this->customer);

        $this->postJson('/api/marketplace-orders', [
            'type' => 'pet-sale',
            'id' => $this->pet->id,
            'quantity' => 2,
        ])->assertUnprocessable();

        $this->postJson('/api/marketplace-orders', [
            'type' => 'product',
            'id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => '1.00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('unit_price');

        $this->product->update(['is_approved' => false]);

        $this->postJson('/api/marketplace-orders', [
            'type' => 'product',
            'id' => $this->product->id,
            'quantity' => 1,
        ])->assertUnprocessable();
    }

    public function test_seller_business_must_still_be_approved_at_acceptance(): void
    {
        $order = $this->order();

        $this->business->update(['status' => 'rejected']);

        Sanctum::actingAs($this->seller);

        $this->respond($order, 'accepted')->assertUnprocessable();

        $this->assertSame(5, $this->product->fresh()->quantity);
        $this->assertSame('pending', $order->fresh()->status);
    }
}