<?php

namespace Tests\Feature\Admin;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        if (
            getenv('DB_CONNECTION') !== 'sqlite'
            || getenv('DB_DATABASE') !== ':memory:'
        ) {
            throw new \RuntimeException(
                'Use a test configuration with SQLite :memory:.'
            );
        }

        if (is_file(__DIR__.'/../../../bootstrap/cache/config.php')) {
            throw new \RuntimeException(
                'Run php artisan config:clear before testing.'
            );
        }

        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    public function test_public_lists_hide_inactive_entries(): void
    {
        foreach ([
            [BusinessType::class, 'business-types'],
            [ProductCategory::class, 'product-categories'],
        ] as [$model, $path]) {
            $active = $model::create([
                'name' => 'Active Entry',
                'slug' => 'active-entry',
                'is_active' => true,
            ]);

            $inactive = $model::create([
                'name' => 'Inactive Entry',
                'slug' => 'inactive-entry',
                'is_active' => false,
            ]);

            $this->getJson("/api/{$path}")
                ->assertOk()
                ->assertJsonPath('count', 1)
                ->assertJsonPath('data.0.id', $active->id);

            $this->getJson("/api/{$path}/{$inactive->id}")
                ->assertNotFound();
        }
    }

    public function test_admin_lists_include_inactive_entries(): void
    {
        Sanctum::actingAs($this->admin);

        foreach ([
            [BusinessType::class, 'business-types'],
            [ProductCategory::class, 'product-categories'],
        ] as [$model, $path]) {
            $model::create([
                'name' => 'Inactive Entry',
                'slug' => 'inactive-entry',
                'is_active' => false,
            ]);

            $this->getJson("/api/admin/{$path}")
                ->assertOk()
                ->assertJsonPath('count', 1)
                ->assertJsonPath('data.0.is_active', false);
        }
    }

    public function test_regular_users_cannot_create_categories(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        foreach (['business-types', 'product-categories'] as $path) {
            $this->postJson("/api/admin/{$path}", [
                'name' => 'Unauthorized Category',
            ])->assertForbidden();
        }

        $this->assertDatabaseCount('business_types', 0);
        $this->assertDatabaseCount('product_categories', 0);
    }

    public function test_admin_can_create_deactivate_and_update_without_reactivation(): void
    {
        Sanctum::actingAs($this->admin);

        foreach (['business-types', 'product-categories'] as $path) {
            $id = $this->postJson("/api/admin/{$path}", [
                'name' => 'Test Category',
            ])->assertCreated()
                ->json('data.id');

            $this->putJson("/api/admin/{$path}/{$id}", [
                'is_active' => false,
            ])->assertOk()
                ->assertJsonPath('data.is_active', false);

            $this->putJson("/api/admin/{$path}/{$id}", [
                'description' => 'Updated description.',
            ])->assertOk()
                ->assertJsonPath('data.is_active', false)
                ->assertJsonPath(
                    'data.description',
                    'Updated description.',
                );
        }
    }

    public function test_slug_collisions_return_validation_errors(): void
    {
        Sanctum::actingAs($this->admin);

        foreach (['business-types', 'product-categories'] as $path) {
            $this->postJson("/api/admin/{$path}", [
                'name' => 'Pet Care',
            ])->assertCreated();

            $this->postJson("/api/admin/{$path}", [
                'name' => 'Pet-Care',
            ])->assertUnprocessable()
                ->assertJsonValidationErrors('name');
        }
    }

    public function test_used_business_type_cannot_delete_businesses(): void
    {
        $type = BusinessType::create([
            'name' => 'Used Type',
            'slug' => 'used-type',
        ]);

        $business = Business::create([
            'user_id' => $this->admin->id,
            'business_type_id' => $type->id,
            'name' => 'Protected Business',
            'slug' => 'protected-business',
            'status' => 'approved',
        ]);

        Sanctum::actingAs($this->admin);

        $this->deleteJson("/api/admin/business-types/{$type->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('business_type');

        $this->assertDatabaseHas('business_types', ['id' => $type->id]);
        $this->assertDatabaseHas('businesses', ['id' => $business->id]);
    }

    public function test_used_product_category_cannot_delete_products(): void
    {
        $category = ProductCategory::create([
            'name' => 'Used Category',
            'slug' => 'used-category',
        ]);

        $product = Product::create([
            'user_id' => $this->admin->id,
            'product_category_id' => $category->id,
            'name' => 'Protected Product',
            'price' => '1500.00',
            'quantity' => 5,
        ]);

        Sanctum::actingAs($this->admin);

        $this->deleteJson(
            "/api/admin/product-categories/{$category->id}"
        )->assertUnprocessable()
            ->assertJsonValidationErrors('product_category');

        $this->assertDatabaseHas('product_categories', [
            'id' => $category->id,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
        ]);
    }

    public function test_unused_categories_can_be_deleted(): void
    {
        Sanctum::actingAs($this->admin);

        foreach ([
            [BusinessType::class, 'business-types', 'business_types'],
            [ProductCategory::class, 'product-categories', 'product_categories'],
        ] as [$model, $path, $table]) {
            $entry = $model::create([
                'name' => 'Unused Entry',
                'slug' => 'unused-entry',
            ]);

            $this->deleteJson("/api/admin/{$path}/{$entry->id}")
                ->assertOk();

            $this->assertDatabaseMissing($table, [
                'id' => $entry->id,
            ]);
        }
    }
}