<?php

namespace Tests\Feature;

use App\Models\PetSaleListing;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketplaceVisibilityTest extends TestCase
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
        $seller = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $category = ProductCategory::create([
            'name' => 'Visibility Test',
            'slug' => 'visibility-test',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $product = Product::create([
            'user_id' => $seller->id,
            'product_category_id' => $category->id,
            'name' => 'Visibility Test Product',
            'price' => '1500.00',
            'quantity' => 5,
            'is_approved' => false,
            'is_active' => true,
        ]);

        $petSale = PetSaleListing::create([
            'user_id' => $seller->id,
            'pet_type' => 'dog',
            'gender' => 'male',
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

    public function test_guests_cannot_view_pending_listings(): void
    {
        foreach ($this->listings() as [$listing, $path]) {
            $this->getJson("/api/{$path}/{$listing->id}")
                ->assertNotFound();

            $this->getJson("/api/{$path}")
                ->assertOk()
                ->assertJsonCount(0, 'data');
        }
    }

    public function test_approved_listings_are_public(): void
    {
        foreach ($this->listings() as [$listing, $path]) {
            $listing->update(['is_approved' => true]);

            $this->getJson("/api/{$path}/{$listing->id}")
                ->assertOk()
                ->assertJsonPath('data.id', $listing->id);

            $this->getJson("/api/{$path}")
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $listing->id);
        }
    }

    public function test_inactive_listings_are_hidden_from_public_browsing(): void
    {
        foreach ($this->listings() as [$listing, $path]) {
            $listing->update([
                'is_approved' => true,
                'is_active' => false,
            ]);

            $this->getJson("/api/{$path}")
                ->assertOk()
                ->assertJsonCount(0, 'data');

            $this->getJson("/api/{$path}/{$listing->id}")
                ->assertNotFound();
        }
    }

    public function test_price_filters_include_and_exclude_listings(): void
    {
        foreach ($this->listings() as [$listing, $path]) {
            $listing->update(['is_approved' => true]);

            $this->getJson(
                "/api/{$path}?min_price=1000&max_price=3000"
            )
                ->assertOk()
                ->assertJsonCount(1, 'data');

            $this->getJson("/api/{$path}?min_price=4000")
                ->assertOk()
                ->assertJsonCount(0, 'data');

            $this->getJson("/api/{$path}?max_price=500")
                ->assertOk()
                ->assertJsonCount(0, 'data');
        }
    }

    public function test_product_category_filter(): void
    {
        $listings = $this->listings();
        $product = $listings[0][0];

        $product->update(['is_approved' => true]);

        $otherCategory = ProductCategory::create([
            'name' => 'Other Category',
            'slug' => 'other-category',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->getJson(
            "/api/products?category_id={$product->product_category_id}"
        )
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson(
            "/api/products?category_id={$otherCategory->id}"
        )
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_pet_type_gender_and_status_filters(): void
    {
        $listings = $this->listings();
        $petSale = $listings[1][0];

        $petSale->update(['is_approved' => true]);

        $this->getJson(
            '/api/pet-sales?pet_type=dog&gender=male&status=available'
        )
            ->assertOk()
            ->assertJsonCount(1, 'data');

        foreach ([
            'pet_type=cat',
            'gender=female',
            'status=sold',
        ] as $filter) {
            $this->getJson("/api/pet-sales?{$filter}")
                ->assertOk()
                ->assertJsonCount(0, 'data');
        }
    }
}