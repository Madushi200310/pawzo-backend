<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Review;
use App\Models\User;
use App\Services\Reviews\BookingVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReviewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('Use phpunit.reviews.xml with SQLite :memory:.');
        }
        if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
            throw new \RuntimeException('Run php artisan config:clear before these tests.');
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('ALLOW_UNVERIFIED_REVIEWS');
        parent::tearDown();
    }

    private function login(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'is_active' => true,
            'email_verified_at' => now(),
            'role' => 'user',
        ], $attributes))->refresh();
        Sanctum::actingAs($user);
        return $user;
    }

    private function admin(): User
    {
        return $this->login(['role' => 'admin']);
    }

    private function makeBusiness(?User $owner = null): Business
    {
        $owner ??= User::factory()->create([
            'is_active' => true, 'email_verified_at' => now(),
        ]);

        $type = BusinessType::firstOrCreate(
            ['slug' => 'groomer'],
            ['name' => 'Groomer', 'slug' => 'groomer'],
        );

        return Business::create([
            'user_id' => $owner->id,
            'business_type_id' => $type->id,
            'name' => 'Test Business',
            'slug' => 'test-business-'.uniqid(),
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    private function enableUnverifiedReviews(): void
    {
        app()['config']->set('reviews.allow_unverified', true);
        $this->app->forgetInstance(BookingVerifier::class);
    }

    // ============================================================
    // Auth & permissions
    // ============================================================

    public function test_guests_cannot_browse_or_post_reviews(): void
    {
        $b = $this->makeBusiness();

        $this->getJson("/api/reviews?type=business&id={$b->id}")
            ->assertUnauthorized();

        $this->postJson('/api/reviews', [
            'type' => 'business', 'id' => $b->id,
            'booking_reference' => 'X', 'rating' => 5,
        ])->assertUnauthorized();
    }

    public function test_unverified_users_are_blocked(): void
    {
        $b = $this->makeBusiness();
        $this->login(['email_verified_at' => null]);

        $this->postJson('/api/reviews', [
            'type' => 'business', 'id' => $b->id,
            'booking_reference' => 'X', 'rating' => 5,
        ])->assertForbidden();
    }

    // ============================================================
    // List & store
    // ============================================================

    public function test_authenticated_user_can_browse_approved_reviews_only(): void
    {
        $b = $this->makeBusiness();
        $u1 = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $u2 = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);

        Review::create([
            'user_id' => $u1->id,
            'reviewable_type' => Business::class,
            'reviewable_id' => $b->id,
            'rating' => 5, 'comment' => 'Good',
            'is_approved' => true, 'approved_at' => now(),
        ]);
        Review::create([
            'user_id' => $u2->id,
            'reviewable_type' => Business::class,
            'reviewable_id' => $b->id,
            'rating' => 1, 'comment' => 'Bad',
            'is_approved' => false,
        ]);

        $this->login();

        $this->getJson("/api/reviews?type=business&id={$b->id}")
            ->assertOk()
            ->assertJsonPath('total_reviews', 1)
            ->assertJsonPath('average_rating', 5);
    }

    public function test_review_requires_verified_booking_when_flag_false(): void
    {
        app()['config']->set('reviews.allow_unverified', false);
        $this->app->forgetInstance(BookingVerifier::class);

        $b = $this->makeBusiness();
        $this->login();

        $this->postJson('/api/reviews', [
            'type' => 'business', 'id' => $b->id,
            'booking_reference' => 'INVALID', 'rating' => 5,
        ])->assertStatus(422)->assertJsonPath('error', 'unverified_booking');
    }

    public function test_review_succeeds_when_flag_true(): void
    {
        $this->enableUnverifiedReviews();

        $b = $this->makeBusiness();
        $user = $this->login();

        $this->postJson('/api/reviews', [
            'type' => 'business', 'id' => $b->id,
            'booking_reference' => 'ANY', 'rating' => 4,
            'comment' => 'Nice service',
        ])->assertCreated()
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.is_approved', false);

        $this->assertDatabaseHas('reviews', [
            'user_id' => $user->id,
            'reviewable_id' => $b->id,
            'rating' => 4,
            'is_approved' => false,
        ]);
    }

    public function test_rating_must_be_1_to_5(): void
    {
        $this->enableUnverifiedReviews();

        $b = $this->makeBusiness();
        $this->login();

        $this->postJson('/api/reviews', [
            'type' => 'business', 'id' => $b->id,
            'booking_reference' => 'ANY', 'rating' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('rating');

        $this->postJson('/api/reviews', [
            'type' => 'business', 'id' => $b->id,
            'booking_reference' => 'ANY', 'rating' => 6,
        ])->assertUnprocessable()->assertJsonValidationErrors('rating');
    }

    public function test_one_review_per_user_per_item(): void
    {
        $this->enableUnverifiedReviews();

        $b = $this->makeBusiness();
        $this->login();

        $this->postJson('/api/reviews', [
            'type' => 'business', 'id' => $b->id,
            'booking_reference' => 'A', 'rating' => 5,
        ])->assertCreated();

        $this->postJson('/api/reviews', [
            'type' => 'business', 'id' => $b->id,
            'booking_reference' => 'B', 'rating' => 3,
        ])->assertStatus(422);
    }

    public function test_owner_can_update_and_delete_own_review(): void
    {
        $this->enableUnverifiedReviews();

        $b = $this->makeBusiness();
        $this->login();

        $created = $this->postJson('/api/reviews', [
            'type' => 'business', 'id' => $b->id,
            'booking_reference' => 'A', 'rating' => 5,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/reviews/{$created}", [
            'rating' => 3,
            'comment' => 'Updated',
        ])->assertOk()
            ->assertJsonPath('data.rating', 3)
            ->assertJsonPath('data.is_approved', false);

        $this->deleteJson("/api/reviews/{$created}")->assertOk();
        $this->assertDatabaseMissing('reviews', ['id' => $created]);
    }

    public function test_other_users_cannot_mutate_a_review(): void
    {
        $this->enableUnverifiedReviews();

        $b = $this->makeBusiness();
        $this->login();
        $review = $this->postJson('/api/reviews', [
            'type' => 'business', 'id' => $b->id,
            'booking_reference' => 'A', 'rating' => 5,
        ])->assertCreated()->json('data.id');

        $this->login();
        $this->patchJson("/api/reviews/{$review}", ['rating' => 1])->assertForbidden();
        $this->deleteJson("/api/reviews/{$review}")->assertForbidden();
    }

    public function test_owner_can_list_their_own_reviews(): void
    {
        $this->enableUnverifiedReviews();

        $b = $this->makeBusiness();
        $this->login();
        $this->postJson('/api/reviews', [
            'type' => 'business', 'id' => $b->id,
            'booking_reference' => 'A', 'rating' => 5,
        ])->assertCreated();

        $this->getJson('/api/reviews/mine')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    // ============================================================
    // Admin moderation
    // ============================================================

    public function test_admin_can_approve_a_pending_review(): void
    {
        $b = $this->makeBusiness();
        $author = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);

        $review = Review::create([
            'user_id' => $author->id,
            'reviewable_type' => Business::class,
            'reviewable_id' => $b->id,
            'rating' => 5, 'comment' => 'Great',
            'is_approved' => false,
        ]);

        $this->admin();
        $this->patchJson("/api/admin/reviews/{$review->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.is_approved', true);

        $this->assertNotNull($review->fresh()->approved_at);
    }

    public function test_admin_can_reject_with_reason(): void
    {
        $b = $this->makeBusiness();
        $author = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $review = Review::create([
            'user_id' => $author->id,
            'reviewable_type' => Business::class,
            'reviewable_id' => $b->id,
            'rating' => 1, 'comment' => 'Spam',
            'is_approved' => false,
        ]);

        $this->admin();
        $this->patchJson("/api/admin/reviews/{$review->id}/reject", [
            'reason' => 'Contains profanity',
        ])->assertOk()
            ->assertJsonPath('data.is_approved', false)
            ->assertJsonPath('data.rejection_reason', 'Contains profanity');
    }

    public function test_admin_can_remove_a_review(): void
    {
        $b = $this->makeBusiness();
        $author = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $review = Review::create([
            'user_id' => $author->id,
            'reviewable_type' => Business::class,
            'reviewable_id' => $b->id,
            'rating' => 5,
            'is_approved' => true, 'approved_at' => now(),
        ]);

        $this->admin();
        $this->deleteJson("/api/admin/reviews/{$review->id}")->assertOk();
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
    }

    public function test_non_admin_cannot_access_admin_endpoints(): void
    {
        $b = $this->makeBusiness();
        $this->login();
        $this->getJson('/api/admin/reviews')->assertForbidden();
    }

    public function test_admin_list_filters(): void
    {
        $b = $this->makeBusiness();
        $author1 = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
        $author2 = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);

        Review::create([
            'user_id' => $author1->id, 'reviewable_type' => Business::class,
            'reviewable_id' => $b->id, 'rating' => 5, 'comment' => 'approve me',
            'is_approved' => true, 'approved_at' => now(),
        ]);
        Review::create([
            'user_id' => $author2->id, 'reviewable_type' => Business::class,
            'reviewable_id' => $b->id, 'rating' => 1, 'comment' => 'pending',
            'is_approved' => false,
        ]);

        $this->admin();
        $this->getJson('/api/admin/reviews?status=approved')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/admin/reviews?status=pending')
            ->assertOk()->assertJsonPath('meta.total', 1);
    }
}