<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketplaceCrudTest extends TestCase
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

        Storage::fake('public');
    }

    private function user(): User
    {
        return User::factory()->create([
            'role' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function businessType(): BusinessType
    {
        return BusinessType::create([
            'name' => 'Test Seller',
            'slug' => 'test-seller',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function approvedSeller(): User
    {
        $seller = $this->user();

        Business::create([
            'user_id' => $seller->id,
            'business_type_id' => $this->businessType()->id,
            'name' => 'Approved Test Business',
            'phone' => '0700000000',
            'email' => $seller->email,
            'status' => 'approved',
            'approved_at' => now(),
        ]);

        return $seller;
    }

    public function test_business_application_owner_crud_and_document_storage(): void
    {
        $owner = $this->user();
        $type = $this->businessType();

        $this->actingAs($owner, 'sanctum');

        $created = $this->postJson('/api/businesses', [
            'business_type_id' => $type->id,
            'name' => 'Test Business Application',
            'phone' => '0700000000',
            'email' => $owner->email,
            'documents' => [
                [
                    'type' => 'Test document',
                    'file' => UploadedFile::fake()->image('document.png'),
                ],
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('business.status', 'pending');

        $id = $created->json('business.id');

        $document = $created->json('business.documents.0.file_path');
        $this->assertNotEmpty($document);
        Storage::disk('public')->assertExists($document);

        $this->getJson("/api/businesses/{$id}")
            ->assertOk()
            ->assertJsonPath('data.user_id', $owner->id);

        $this->putJson("/api/businesses/{$id}", [
            'name' => 'Updated Test Business',
        ])
            ->assertOk()
            ->assertJsonPath('business.name', 'Updated Test Business');

        $this->actingAs($this->user(), 'sanctum');

        $this->getJson("/api/businesses/{$id}")->assertNotFound();
        $this->putJson("/api/businesses/{$id}", [
            'name' => 'Unauthorized change',
        ])->assertForbidden();
        $this->deleteJson("/api/businesses/{$id}")->assertForbidden();

        $this->actingAs($owner, 'sanctum');

        $this->deleteJson("/api/businesses/{$id}")->assertOk();

        $this->assertDatabaseMissing('businesses', ['id' => $id]);
        Storage::disk('public')->assertMissing($document);
    }

    public function test_product_owner_crud_and_other_user_permissions(): void
    {
        $seller = $this->approvedSeller();

        $category = ProductCategory::create([
            'name' => 'Test Products',
            'slug' => 'test-products',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->actingAs($seller, 'sanctum');

        $created = $this->postJson('/api/products', [
            'product_category_id' => $category->id,
            'name' => 'Test Product',
            'price' => 1500,
            'quantity' => 5,
        ])
            ->assertCreated()
            ->assertJsonPath('data.is_approved', false);

        $id = $created->json('data.id');

        $this->getJson("/api/products/{$id}")->assertOk();

        $this->putJson("/api/products/{$id}", [
            'price' => 1800,
            'quantity' => 3,
        ])->assertOk();

        $this->assertDatabaseHas('products', [
            'id' => $id,
            'quantity' => 3,
        ]);

        $this->actingAs($this->user(), 'sanctum');

        $this->getJson("/api/products/{$id}")->assertNotFound();
        $this->putJson("/api/products/{$id}", [
            'quantity' => 99,
        ])->assertForbidden();
        $this->deleteJson("/api/products/{$id}")->assertForbidden();

        $this->actingAs($seller, 'sanctum');

        $this->deleteJson("/api/products/{$id}")->assertOk();
        $this->assertDatabaseMissing('products', ['id' => $id]);
    }

    public function test_pet_sale_owner_crud_and_other_user_permissions(): void
    {
        $seller = $this->approvedSeller();

        $this->actingAs($seller, 'sanctum');

        $created = $this->postJson('/api/pet-sales', [
            'pet_type' => 'dog',
            'gender' => 'male',
            'price' => 2000,
            'contact_phone' => '0700000000',
        ])
            ->assertCreated()
            ->assertJsonPath('data.is_approved', false);

        $id = $created->json('data.id');

        $this->getJson("/api/pet-sales/{$id}")->assertOk();

        $this->putJson("/api/pet-sales/{$id}", [
            'description' => 'Updated listing description.',
            'price' => 2500,
        ])
            ->assertOk()
            ->assertJsonPath(
                'data.description',
                'Updated listing description.',
            );

        $this->actingAs($this->user(), 'sanctum');

        $this->getJson("/api/pet-sales/{$id}")->assertNotFound();
        $this->putJson("/api/pet-sales/{$id}", [
            'price' => 1,
        ])->assertForbidden();
        $this->deleteJson("/api/pet-sales/{$id}")->assertForbidden();

        $this->actingAs($seller, 'sanctum');

        $this->deleteJson("/api/pet-sales/{$id}")->assertOk();
        $this->assertDatabaseMissing('pet_sale_listings', ['id' => $id]);
    }

    public function test_guests_cannot_create_marketplace_records(): void
    {
        foreach (['businesses', 'products', 'pet-sales'] as $path) {
            $this->postJson("/api/{$path}", [])
                ->assertUnauthorized();
        }
    }

    public function test_invalid_create_requests_are_rejected(): void
    {
        $this->actingAs($this->approvedSeller(), 'sanctum');

        $this->postJson('/api/businesses', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'business_type_id',
                'name',
                'phone',
                'email',
                'documents',
            ]);

        $this->postJson('/api/products', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'product_category_id',
                'name',
                'price',
                'quantity',
            ]);

        $this->postJson('/api/pet-sales', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'pet_type',
                'gender',
                'price',
                'contact_phone',
            ]);
    }
}