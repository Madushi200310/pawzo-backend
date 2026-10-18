<?php

namespace Tests\Feature\Admin;

use App\Models\PetSaleListing;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceModerationTest extends TestCase
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

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function listings(): array
    {
        $seller = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $category = ProductCategory::create([
            'name' => 'Moderation Test Category',
            'slug' => 'moderation-test-category',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $product = Product::create([
            'user_id' => $seller->id,
            'product_category_id' => $category->id,
            'name' => 'Moderation Test Product',
            'price' => '1500.00',
            'quantity' => 5,
            'is_approved' => false,
            'is_active' => true,
        ]);

        $petSale = PetSaleListing::create([
            'user_id' => $seller->id,
            'pet_type' => 'dog',
            'gender' => 'unknown',
            'price' => '2500.00',
            'status' => 'available',
            'is_approved' => false,
            'is_active' => true,
        ]);

        return [
            [$product, 'products'],
            [$petSale, 'pet-sales'],
        ];
    }

    public function test_admin_can_list_view_and_read_statistics(): void
    {
        $listings = $this->listings();

        $this->actingAs($this->admin(), 'sanctum');

        foreach ($listings as [$listing, $path]) {
            $this->getJson("/api/admin/{$path}?status=pending")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $listing->id);

            $this->getJson("/api/admin/{$path}/{$listing->id}")
                ->assertOk()
                ->assertJsonPath('data.id', $listing->id);

            $this->getJson("/api/admin/{$path}/stats")
                ->assertOk()
                ->assertJsonPath('pending', 1)
                ->assertJsonPath('approved', 0)
                ->assertJsonPath('total', 1);
        }
    }

    public function test_admin_can_approve_pending_listings(): void
    {
        $listings = $this->listings();

        $this->actingAs($this->admin(), 'sanctum');

        foreach ($listings as [$listing, $path]) {
            $listing->update([
                'rejection_reason' => 'Previous rejection.',
            ]);

            $this->postJson(
                "/api/admin/{$path}/{$listing->id}/approve"
            )
                ->assertOk()
                ->assertJsonPath('data.is_approved', true)
                ->assertJsonPath('data.rejection_reason', null);

            $this->assertTrue($listing->fresh()->is_approved);
            $this->assertNull($listing->fresh()->rejection_reason);

            $this->getJson("/api/admin/{$path}?status=approved")
                ->assertOk()
                ->assertJsonCount(1, 'data');

            $this->getJson("/api/admin/{$path}/stats")
                ->assertOk()
                ->assertJsonPath('approved', 1)
                ->assertJsonPath('pending', 0);
        }

        $this->app['auth']->forgetGuards();

        foreach ($listings as [$listing, $path]) {
            $this->getJson("/api/{$path}/{$listing->id}")
                ->assertOk();
        }
    }

    public function test_admin_rejection_hides_listings_from_guests(): void
    {
        $listings = $this->listings();

        $this->actingAs($this->admin(), 'sanctum');

        foreach ($listings as [$listing, $path]) {
            $listing->update(['is_approved' => true]);

            $this->postJson(
                "/api/admin/{$path}/{$listing->id}/reject",
                ['reason' => 'Invalid listing information.'],
            )
                ->assertOk()
                ->assertJsonPath('data.is_approved', false)
                ->assertJsonPath(
                    'data.rejection_reason',
                    'Invalid listing information.',
                );

            $this->assertFalse($listing->fresh()->is_approved);
        }

        $this->app['auth']->forgetGuards();

        foreach ($listings as [$listing, $path]) {
            $this->getJson("/api/{$path}/{$listing->id}")
                ->assertNotFound();

            $this->getJson("/api/{$path}")
                ->assertOk()
                ->assertJsonCount(0, 'data');
        }
    }

    public function test_rejection_requires_a_reason(): void
    {
        $listings = $this->listings();

        $this->actingAs($this->admin(), 'sanctum');

        foreach ($listings as [$listing, $path]) {
            $listing->update(['is_approved' => true]);

            $this->postJson(
                "/api/admin/{$path}/{$listing->id}/reject",
                [],
            )
                ->assertUnprocessable()
                ->assertJsonValidationErrors('reason');

            $this->assertTrue($listing->fresh()->is_approved);
        }
    }

    public function test_normal_users_cannot_access_moderation(): void
    {
        $listings = $this->listings();

        $this->actingAs(User::factory()->create([
            'role' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]), 'sanctum');

        foreach ($listings as [$listing, $path]) {
            $this->getJson("/api/admin/{$path}")
                ->assertForbidden();

            $this->getJson("/api/admin/{$path}/stats")
                ->assertForbidden();

            $this->getJson("/api/admin/{$path}/{$listing->id}")
                ->assertForbidden();

            $this->postJson(
                "/api/admin/{$path}/{$listing->id}/approve"
            )->assertForbidden();

            $this->postJson(
                "/api/admin/{$path}/{$listing->id}/reject",
                ['reason' => 'Unauthorized rejection.'],
            )->assertForbidden();

            $this->assertFalse($listing->fresh()->is_approved);
            $this->assertNull($listing->fresh()->rejection_reason);
        }
    }

    public function test_guests_cannot_access_moderation(): void
    {
        foreach ($this->listings() as [$listing, $path]) {
            $this->getJson("/api/admin/{$path}")
                ->assertUnauthorized();

            $this->getJson("/api/admin/{$path}/stats")
                ->assertUnauthorized();

            $this->getJson("/api/admin/{$path}/{$listing->id}")
                ->assertUnauthorized();

            $this->postJson(
                "/api/admin/{$path}/{$listing->id}/approve"
            )->assertUnauthorized();

            $this->postJson(
                "/api/admin/{$path}/{$listing->id}/reject",
                ['reason' => 'Unauthorized rejection.'],
            )->assertUnauthorized();
        }
    }
}