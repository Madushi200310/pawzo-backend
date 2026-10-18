<?php

namespace Tests\Feature\Admin;

use App\Models\PetSaleListing;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMarketplaceRemovalTest extends TestCase
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

    private function listings(): array
    {
        $seller = User::factory()->create();

        $category = ProductCategory::create([
            'name' => 'Removal Test Category',
            'slug' => 'removal-test-category',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $product = Product::create([
            'user_id' => $seller->id,
            'product_category_id' => $category->id,
            'name' => 'Removal Test Product',
            'price' => '1500.00',
            'quantity' => 5,
            'photos' => [],
            'is_approved' => true,
            'is_active' => true,
        ]);

        $petSale = PetSaleListing::create([
            'user_id' => $seller->id,
            'pet_type' => 'dog',
            'gender' => 'male',
            'price' => '2000.00',
            'photos' => [],
            'certificates' => [],
            'status' => 'available',
            'is_approved' => true,
            'is_active' => true,
        ]);

        return [
            [$product, 'products'],
            [$petSale, 'pet-sales'],
        ];
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    public function test_admin_removal_hides_listings_and_preserves_records(): void
    {
        $listings = $this->listings();

        foreach ($listings as [$listing, $path]) {
            $this->getJson("/api/{$path}/{$listing->id}")
                ->assertOk();
        }

        $this->actingAs($this->admin(), 'sanctum');

        foreach ($listings as [$listing, $path]) {
            $this->patchJson(
                "/api/admin/{$path}/{$listing->id}/remove",
                ['reason' => 'Removed after investigation.'],
            )
                ->assertOk()
                ->assertJsonPath('data.is_active', false)
                ->assertJsonPath('data.is_approved', false)
                ->assertJsonPath(
                    'data.rejection_reason',
                    'Removed after investigation.',
                );

            $this->assertDatabaseHas($listing->getTable(), [
                'id' => $listing->id,
                'is_active' => false,
                'is_approved' => false,
            ]);

            // A separate guest request must not see the removed listing.
            $this->app['auth']->forgetGuards();

            $this->getJson("/api/{$path}/{$listing->id}")
                ->assertNotFound();

            $this->actingAs($this->admin(), 'sanctum');
        }

        $this->assertSame(5, $listings[0][0]->fresh()->quantity);
        $this->assertSame(
            'available',
            $listings[1][0]->fresh()->status,
        );
    }

    public function test_guests_and_normal_users_cannot_remove_listings(): void
    {
        $listings = $this->listings();

        foreach ($listings as [$listing, $path]) {
            $this->patchJson(
                "/api/admin/{$path}/{$listing->id}/remove",
                ['reason' => 'Unauthorized test.'],
            )->assertUnauthorized();
        }

        $this->actingAs(User::factory()->create([
            'role' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]), 'sanctum');

        foreach ($listings as [$listing, $path]) {
            $this->patchJson(
                "/api/admin/{$path}/{$listing->id}/remove",
                ['reason' => 'Unauthorized test.'],
            )->assertForbidden();

            $this->assertTrue($listing->fresh()->is_active);
            $this->assertTrue($listing->fresh()->is_approved);
        }
    }

    public function test_removal_requires_a_reason(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        foreach ($this->listings() as [$listing, $path]) {
            $this->patchJson(
                "/api/admin/{$path}/{$listing->id}/remove",
                [],
            )
                ->assertUnprocessable()
                ->assertJsonValidationErrors('reason');

            $this->assertTrue($listing->fresh()->is_active);
        }
    }

    public function test_repeated_removal_is_safe(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        foreach ($this->listings() as [$listing, $path]) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $this->patchJson(
                    "/api/admin/{$path}/{$listing->id}/remove",
                    ['reason' => 'Repeated removal test.'],
                )
                    ->assertOk()
                    ->assertJsonPath('data.is_active', false);
            }

            $this->assertSame(
                1,
                $listing->newQuery()
                    ->whereKey($listing->id)
                    ->count(),
            );
        }
    }

    public function test_inactive_or_unverified_admin_cannot_remove(): void
    {
        $listings = $this->listings();

        foreach ([
            ['is_active' => false, 'email_verified_at' => now()],
            ['is_active' => true, 'email_verified_at' => null],
        ] as $attributes) {
            $admin = User::factory()->create([
                'role' => 'admin',
                ...$attributes,
            ]);

            $this->actingAs($admin, 'sanctum');

            foreach ($listings as [$listing, $path]) {
                $this->patchJson(
                    "/api/admin/{$path}/{$listing->id}/remove",
                    ['reason' => 'Blocked admin test.'],
                )->assertForbidden();

                $this->assertTrue($listing->fresh()->is_active);
            }
        }
    }
}