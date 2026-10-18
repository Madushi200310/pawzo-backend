<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceSellerEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (
            getenv('DB_CONNECTION') !== 'sqlite'
            || getenv('DB_DATABASE') !== ':memory:'
        ) {
            throw new \RuntimeException(
                'Use SQLite :memory: for these tests.'
            );
        }

        parent::setUp();
    }

    private function seller(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function business(User $user, string $status): void
    {
        $type = BusinessType::firstOrCreate(
            ['slug' => 'eligibility-test'],
            [
                'name' => 'Eligibility Test',
                'is_active' => true,
                'sort_order' => 0,
            ],
        );

        Business::create([
            'user_id' => $user->id,
            'business_type_id' => $type->id,
            'name' => 'Eligibility Test Business',
            'phone' => '0700000000',
            'email' => $user->email,
            'status' => $status,
        ]);
    }

    private function assertCreationBlocked(): void
    {
        foreach (['products', 'pet-sales'] as $path) {
            $this->postJson("/api/{$path}", [])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('pet_sale_listings', 0);
    }

    public function test_user_without_business_cannot_create_listings(): void
    {
        $this->actingAs($this->seller(), 'sanctum');

        $this->assertCreationBlocked();
    }

    public function test_pending_and_rejected_businesses_do_not_qualify(): void
    {
        foreach (['pending', 'rejected'] as $status) {
            $user = $this->seller();
            $this->business($user, $status);

            $this->actingAs($user, 'sanctum');

            $this->assertCreationBlocked();
        }
    }

    public function test_inactive_or_unverified_seller_is_blocked(): void
    {
        foreach ([
            ['is_active' => false],
            ['email_verified_at' => null],
        ] as $attributes) {
            $user = $this->seller($attributes);
            $this->business($user, 'approved');

            $this->actingAs($user, 'sanctum');

            $this->assertCreationBlocked();
        }
    }

    public function test_approved_seller_reaches_request_validation(): void
    {
        $user = $this->seller();
        $this->business($user, 'approved');

        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/products', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        $this->postJson('/api/pet-sales', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pet_type');
    }

    public function test_guests_can_still_browse_but_cannot_create(): void
    {
        foreach (['products', 'pet-sales'] as $path) {
            $this->getJson("/api/{$path}")->assertOk();

            $this->postJson("/api/{$path}", [])
                ->assertUnauthorized();
        }
    }
}