<?php

namespace Tests\Feature\Admin;

use App\Models\ListingCategory;
use App\Models\Pet;
use App\Models\User;
use App\Rules\ListingCategoryValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ListingCategoriesTest extends TestCase
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

    private function category(array $overrides = []): ListingCategory
    {
        return ListingCategory::create(array_replace([
            'group' => 'service',
            'value' => 'pet_spa',
            'name' => 'Pet Spa',
            'is_active' => true,
            'sort_order' => 0,
        ], $overrides));
    }

    public function test_public_list_hides_inactive_categories(): void
    {
        $active = $this->category();

        $this->category([
            'value' => 'inactive_spa',
            'name' => 'Inactive Spa',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/listing-categories?group=service')
            ->assertOk();

        $ids = array_column($response->json('data'), 'id');

        $this->assertContains($active->id, $ids);

        foreach ($response->json('data') as $category) {
            $this->assertTrue($category['is_active']);
        }
    }

    public function test_regular_user_cannot_manage_categories(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]));

        $this->postJson('/api/admin/listing-categories', [
            'group' => 'service',
            'value' => 'pet_spa',
            'name' => 'Pet Spa',
        ])->assertForbidden();

        $this->getJson('/api/admin/listing-categories?group=service')
            ->assertForbidden();
    }

    public function test_admin_can_create_and_deactivate_category(): void
    {
        Sanctum::actingAs($this->admin);

        $id = $this->postJson('/api/admin/listing-categories', [
            'group' => 'service',
            'value' => 'pet_spa',
            'name' => 'Pet Spa',
        ])->assertCreated()
            ->json('data.id');

        $this->putJson("/api/admin/listing-categories/{$id}", [
            'name' => 'Premium Pet Spa',
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Premium Pet Spa')
            ->assertJsonPath('data.is_active', false);

        $response = $this->getJson(
            '/api/admin/listing-categories?group=service'
        )->assertOk();

        $entry = collect($response->json('data'))->firstWhere('id', $id);

        $this->assertNotNull($entry);
        $this->assertFalse($entry['is_active']);
    }

    public function test_category_value_and_group_cannot_be_renamed(): void
    {
        $category = $this->category();
        Sanctum::actingAs($this->admin);

        $this->putJson(
            "/api/admin/listing-categories/{$category->id}",
            ['value' => 'different_value', 'group' => 'rental'],
        )->assertUnprocessable()
            ->assertJsonValidationErrors(['value', 'group']);

        $this->assertSame('pet_spa', $category->fresh()->value);
        $this->assertSame('service', $category->fresh()->group);
    }

    public function test_new_values_are_accepted_in_the_correct_group_only(): void
    {
        $this->category();

        $this->assertTrue(Validator::make(
            ['type' => 'pet_spa'],
            ['type' => [new ListingCategoryValue('service')]],
        )->passes());

        $this->assertTrue(Validator::make(
            ['type' => 'pet_spa'],
            ['type' => [new ListingCategoryValue('rental')]],
        )->fails());
    }

    public function test_inactive_values_block_new_records_but_allow_unchanged_updates(): void
    {
        $this->category(['is_active' => false]);

        $this->assertTrue(Validator::make(
            ['type' => 'pet_spa'],
            ['type' => [new ListingCategoryValue('service')]],
        )->fails());

        $this->assertTrue(Validator::make(
            ['type' => 'pet_spa'],
            ['type' => [
                new ListingCategoryValue('service', 'pet_spa'),
            ]],
        )->passes());

        $this->assertTrue(Validator::make(
            ['type' => 'pet_spa'],
            ['type' => [
                new ListingCategoryValue('service', 'grooming'),
            ]],
        )->fails());

        $this->assertTrue(Validator::make(
            ['type' => 'pet_spa'],
            ['type' => [
                new ListingCategoryValue('service', activeOnly: false),
            ]],
        )->passes());
    }

    public function test_pet_profiles_accept_existing_title_case_values(): void
    {
        $this->assertTrue(Validator::make(
            ['type' => 'Dog'],
            ['type' => [new ListingCategoryValue('pet')]],
        )->passes());
    }

    public function test_admin_cannot_delete_a_category_used_by_a_pet(): void
    {
        $category = ListingCategory::where('group', 'pet')
            ->where('value', 'dog')
            ->firstOrFail();

        Pet::factory()->create([
            'user_id' => $this->admin->id,
            'type' => 'Dog',
        ]);

        Sanctum::actingAs($this->admin);

        $this->deleteJson(
            "/api/admin/listing-categories/{$category->id}"
        )->assertUnprocessable()
            ->assertJsonValidationErrors('category');

        $this->assertDatabaseHas('listing_categories', [
            'id' => $category->id,
        ]);
    }

    public function test_admin_can_delete_unused_category(): void
    {
        $category = $this->category();
        Sanctum::actingAs($this->admin);

        $this->deleteJson(
            "/api/admin/listing-categories/{$category->id}"
        )->assertOk();

        $this->assertDatabaseMissing('listing_categories', [
            'id' => $category->id,
        ]);
    }

    public function test_duplicate_value_is_blocked_within_group(): void
    {
        $this->category();
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/listing-categories', [
            'group' => 'service',
            'value' => 'pet_spa',
            'name' => 'Duplicate Spa',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('value');

        $this->postJson('/api/admin/listing-categories', [
            'group' => 'rental',
            'value' => 'pet_spa',
            'name' => 'Separate Group Entry',
        ])->assertCreated();
    }
}