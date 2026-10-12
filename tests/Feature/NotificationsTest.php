<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('Use phpunit.notifications.xml with SQLite :memory:.');
        }
        if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
            throw new \RuntimeException('Run php artisan config:clear before these tests.');
        }
        parent::setUp();
    }

    private function login(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'is_active' => true,
            'email_verified_at' => now(),
        ], $attributes))->refresh();
        Sanctum::actingAs($user);
        return $user;
    }

    public function test_guests_cannot_access_notifications(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->getJson('/api/notifications/unread-count')->assertUnauthorized();
        $this->postJson('/api/notifications/read-all')->assertUnauthorized();
    }

    public function test_unverified_users_are_blocked(): void
    {
        $this->login(['email_verified_at' => null]);
        $this->getJson('/api/notifications')->assertForbidden();
    }

    public function test_user_sees_only_their_own_notifications(): void
    {
        $userA = $this->login();
        $userB = User::factory()->create([
            'is_active' => true, 'email_verified_at' => now(),
        ]);

        Notification::create([
            'user_id' => $userA->id, 'type' => Notification::TYPE_SMART_MATCH,
            'title' => 'A', 'body' => 'Body A',
        ]);
        Notification::create([
            'user_id' => $userB->id, 'type' => Notification::TYPE_SMART_MATCH,
            'title' => 'B', 'body' => 'Body B',
        ]);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'A');
    }

    public function test_empty_list_returns_paginated_structure(): void
    {
        $this->login();
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_unread_count_works(): void
    {
        $user = $this->login();

        Notification::create([
            'user_id' => $user->id, 'type' => Notification::TYPE_SMART_MATCH,
            'title' => 'X', 'body' => 'Body',
        ]);
        Notification::create([
            'user_id' => $user->id, 'type' => Notification::TYPE_SMART_MATCH,
            'title' => 'Y', 'body' => 'Body',
            'read_at' => now(),
        ]);

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    public function test_status_filter_works(): void
    {
        $user = $this->login();

        Notification::create([
            'user_id' => $user->id, 'type' => Notification::TYPE_SMART_MATCH,
            'title' => 'Unread', 'body' => 'Body',
        ]);
        Notification::create([
            'user_id' => $user->id, 'type' => Notification::TYPE_SMART_MATCH,
            'title' => 'Read', 'body' => 'Body', 'read_at' => now(),
        ]);

        $this->getJson('/api/notifications?status=unread')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Unread');

        $this->getJson('/api/notifications?status=read')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Read');
    }

    public function test_type_filter_works(): void
    {
        $user = $this->login();

        Notification::create([
            'user_id' => $user->id, 'type' => Notification::TYPE_SMART_MATCH,
            'title' => 'Match', 'body' => 'Body',
        ]);
        Notification::create([
            'user_id' => $user->id, 'type' => Notification::TYPE_VACCINATION_REMINDER,
            'title' => 'Vaccine', 'body' => 'Body',
        ]);

        $this->getJson('/api/notifications?type='.Notification::TYPE_VACCINATION_REMINDER)
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Vaccine');
    }

    public function test_owner_can_mark_notification_as_read(): void
    {
        $user = $this->login();
        $n = Notification::create([
            'user_id' => $user->id, 'type' => Notification::TYPE_SMART_MATCH,
            'title' => 'X', 'body' => 'Body',
        ]);

        $response = $this->patchJson("/api/notifications/{$n->id}/read");
        $response->assertOk();

        // Controller uses ->additional([...]) which flattens single-resource
        // responses to the ROOT level (no 'data' wrapper). So assert on
        // top-level keys directly.
        $response->assertJsonPath('is_read', true);
        $response->assertJsonPath('id', $n->id);
        $this->assertNotNull($response->json('read_at'));

        // Database state
        $this->assertNotNull($n->fresh()->read_at);
    }

    public function test_other_users_cannot_mark_notification_read(): void
    {
        $owner = User::factory()->create([
            'is_active' => true, 'email_verified_at' => now(),
        ]);
        $n = Notification::create([
            'user_id' => $owner->id, 'type' => Notification::TYPE_SMART_MATCH,
            'title' => 'X', 'body' => 'Body',
        ]);

        $this->login();
        $this->patchJson("/api/notifications/{$n->id}/read")->assertForbidden();
        $this->deleteJson("/api/notifications/{$n->id}")->assertForbidden();
    }

    public function test_read_all_marks_every_unread_notification(): void
    {
        $user = $this->login();

        for ($i = 0; $i < 5; $i++) {
            Notification::create([
                'user_id' => $user->id, 'type' => Notification::TYPE_SMART_MATCH,
                'title' => "N{$i}", 'body' => 'Body',
            ]);
        }

        $this->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('count', 5);

        $this->assertSame(0, Notification::where('user_id', $user->id)
            ->whereNull('read_at')->count());
    }

    public function test_owner_can_delete_notification(): void
    {
        $user = $this->login();
        $n = Notification::create([
            'user_id' => $user->id, 'type' => Notification::TYPE_SMART_MATCH,
            'title' => 'X', 'body' => 'Body',
        ]);

        $this->deleteJson("/api/notifications/{$n->id}")->assertOk();
        $this->assertDatabaseMissing('notifications', ['id' => $n->id]);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->login();
        $this->getJson('/api/notifications?status=banana')->assertUnprocessable();
        $this->getJson('/api/notifications?type=banana')->assertUnprocessable();
        $this->getJson('/api/notifications?per_page=1000')->assertUnprocessable();
    }

    public function test_notification_not_found_returns_404(): void
    {
        $this->login();
        $this->patchJson('/api/notifications/999999/read')->assertNotFound();
    }

    // =========================================================
    // Service-level tests
    // =========================================================

    public function test_service_smart_match_creates_correct_notification(): void
    {
        $user = User::factory()->create([
            'is_active' => true, 'email_verified_at' => now(),
        ]);
        $service = new NotificationService();

        $n = $service->smartMatchFound($user, 42, 'lost', 99, 87.5);

        $this->assertSame(Notification::TYPE_SMART_MATCH, $n->type);
        $this->assertStringContainsString('match', strtolower($n->title));
        $this->assertSame(42, $n->data['report_id']);
        $this->assertSame('lost', $n->data['report_type']);
        $this->assertSame(99, $n->data['candidate_id']);
        $this->assertSame(87.5, $n->data['score']);
        $this->assertNotNull($n->action_url);
    }

    public function test_service_vaccination_reminder_builds_correctly(): void
    {
        $user = User::factory()->create([
            'is_active' => true, 'email_verified_at' => now(),
        ]);
        $service = new NotificationService();

        $n = $service->vaccinationReminder($user, 5, 'Bruno', 'Rabies', '2026-11-01');

        $this->assertSame(Notification::TYPE_VACCINATION_REMINDER, $n->type);
        $this->assertStringContainsString('Bruno', $n->title);
        $this->assertSame('Rabies', $n->data['vaccine_name']);
        $this->assertSame('2026-11-01', $n->data['due_date']);
    }

    public function test_service_medicine_reminder_builds_correctly(): void
    {
        $user = User::factory()->create([
            'is_active' => true, 'email_verified_at' => now(),
        ]);
        $service = new NotificationService();

        $n = $service->medicineReminder($user, 5, 'Bruno', 'Heartworm pill', '08:00');

        $this->assertSame(Notification::TYPE_MEDICINE_REMINDER, $n->type);
        $this->assertSame('Heartworm pill', $n->data['medicine_name']);
        $this->assertSame('08:00', $n->data['scheduled_time']);
    }

    public function test_service_rental_request_builds_correctly(): void
    {
        $owner = User::factory()->create([
            'is_active' => true, 'email_verified_at' => now(),
        ]);
        $service = new NotificationService();

        $n = $service->rentalRequest($owner, 7, 'Simba', 3, 'Director Kamal', '2026-11-05', '2026-11-07');

        $this->assertSame(Notification::TYPE_RENTAL_REQUEST, $n->type);
        $this->assertStringContainsString('Simba', $n->title);
        $this->assertSame('Director Kamal', $n->data['requester_name']);
        $this->assertSame('2026-11-05', $n->data['start_date']);
    }

    public function test_service_business_approved_builds_correctly(): void
    {
        $owner = User::factory()->create([
            'is_active' => true, 'email_verified_at' => now(),
        ]);
        $service = new NotificationService();

        $n = $service->businessApproved($owner, 10, 'Happy Paws Grooming');

        $this->assertSame(Notification::TYPE_BUSINESS_APPROVED, $n->type);
        $this->assertStringContainsString('approved', strtolower($n->title));
        $this->assertSame('Happy Paws Grooming', $n->data['business_name']);
    }

    public function test_service_business_rejected_builds_correctly(): void
    {
        $owner = User::factory()->create([
            'is_active' => true, 'email_verified_at' => now(),
        ]);
        $service = new NotificationService();

        $n = $service->businessRejected($owner, 10, 'Fake Groomers', 'License expired');

        $this->assertSame(Notification::TYPE_BUSINESS_REJECTED, $n->type);
        $this->assertStringContainsString('rejected', strtolower($n->title));
        $this->assertSame('License expired', $n->data['reason']);
    }

    public function test_pagination_works(): void
    {
        $user = $this->login();
        for ($i = 0; $i < 5; $i++) {
            Notification::create([
                'user_id' => $user->id, 'type' => Notification::TYPE_SMART_MATCH,
                'title' => "N{$i}", 'body' => 'Body',
            ]);
        }

        $this->getJson('/api/notifications?per_page=2&page=1')
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.current_page', 1);

        $this->getJson('/api/notifications?per_page=2&page=3')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 3);
    }
}