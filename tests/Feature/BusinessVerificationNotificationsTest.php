<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\BusinessType;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessVerificationNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (
            getenv('DB_CONNECTION') !== 'sqlite'
            || getenv('DB_DATABASE') !== ':memory:'
        ) {
            throw new \RuntimeException(
                'Use a PHPUnit configuration with SQLite :memory:.'
            );
        }

        if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
            throw new \RuntimeException(
                'Run php artisan config:clear first.'
            );
        }

        parent::setUp();
    }

    private function user(string $role = 'user'): User
    {
        return User::factory()->create([
            'role' => $role,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function business(User $owner): Business
    {
        $type = BusinessType::firstOrCreate(
            ['slug' => 'test-boarding'],
            [
                'name' => 'Test Boarding',
                'is_active' => true,
            ]
        );

        return Business::create([
            'user_id' => $owner->id,
            'business_type_id' => $type->id,
            'name' => 'Happy Paws Boarding',
            'status' => 'pending',
        ]);
    }

    public function test_approval_notifies_owner_and_repeated_approval_does_not_duplicate(): void
    {
        $owner = $this->user();
        $admin = $this->user('admin');
        $business = $this->business($owner);

        Sanctum::actingAs($admin);

        $url = "/api/admin/business-verifications/{$business->id}/approve";

        $this->postJson($url)
            ->assertOk()
            ->assertJsonPath('business.status', 'approved');

        $fresh = $business->fresh();

        $this->assertSame(
            (int) $admin->id,
            (int) $fresh->approved_by
        );
        $this->assertNotNull($fresh->approved_at);
        $this->assertNull($fresh->rejection_reason);

        $notification = Notification::query()
            ->where('user_id', $owner->id)
            ->sole();

        $this->assertSame(
            Notification::TYPE_BUSINESS_APPROVED,
            $notification->type
        );
        $this->assertSame(
            (int) $business->id,
            $notification->data['business_id']
        );
        $this->assertSame(
            $business->name,
            $notification->data['business_name']
        );
        $this->assertSame(
            "/api/businesses/{$business->id}",
            $notification->action_url
        );
        $this->assertNull($notification->read_at);

        // Preserve the controller's existing repeated-approval response.
        $this->postJson($url)->assertUnprocessable();

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_rejection_notifies_owner_with_reason_without_duplicate_on_retry(): void
    {
        $owner = $this->user();
        $admin = $this->user('admin');
        $business = $this->business($owner);

        Sanctum::actingAs($admin);

        $url = "/api/admin/business-verifications/{$business->id}/reject";

        $payload = ['reason' => 'Please upload a valid license.'];

        $this->postJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('business.status', 'rejected');

        $fresh = $business->fresh();

        $this->assertNull($fresh->approved_at);
        $this->assertSame(
            $payload['reason'],
            $fresh->rejection_reason
        );

        $notification = Notification::query()
            ->where('user_id', $owner->id)
            ->sole();

        $this->assertSame(
            Notification::TYPE_BUSINESS_REJECTED,
            $notification->type
        );
        $this->assertSame(
            (int) $business->id,
            $notification->data['business_id']
        );
        $this->assertSame(
            $payload['reason'],
            $notification->data['reason']
        );
        $this->assertSame(
            "/api/businesses/{$business->id}",
            $notification->action_url
        );

        $this->postJson($url, $payload)->assertOk();

        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_rejection_requires_a_reason_and_does_not_change_business_on_failure(): void
    {
        $owner = $this->user();
        $business = $this->business($owner);

        Sanctum::actingAs($this->user('admin'));

        $this->postJson(
            "/api/admin/business-verifications/{$business->id}/reject",
            []
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->assertSame('pending', $business->fresh()->status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_regular_users_cannot_approve_or_reject_businesses(): void
    {
        $owner = $this->user();
        $business = $this->business($owner);

        Sanctum::actingAs($owner);

        $this->postJson(
            "/api/admin/business-verifications/{$business->id}/approve"
        )->assertForbidden();

        $this->postJson(
            "/api/admin/business-verifications/{$business->id}/reject",
            ['reason' => 'Not permitted']
        )->assertForbidden();

        $this->assertSame('pending', $business->fresh()->status);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_notification_failure_rolls_back_business_approval(): void
    {
        $owner = $this->user();
        $admin = $this->user('admin');
        $business = $this->business($owner);

        $this->mock(
            NotificationService::class,
            function ($mock) {
                $mock->shouldReceive('businessApproved')
                    ->once()
                    ->andThrow(
                        new \RuntimeException(
                            'Simulated business notification failure'
                        )
                    );
            }
        );

        Sanctum::actingAs($admin);

        $this->withoutExceptionHandling();

        $caught = false;

        try {
            $this->postJson(
                "/api/admin/business-verifications/{$business->id}/approve"
            );
        } catch (\RuntimeException $error) {
            $caught = true;

            $this->assertSame(
                'Simulated business notification failure',
                $error->getMessage()
            );
        }

        $this->assertTrue(
            $caught,
            'Expected the notification failure to propagate.'
        );

        $fresh = $business->fresh();

        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->approved_at);
        $this->assertNull($fresh->approved_by);
        $this->assertDatabaseCount('notifications', 0);
    }
}