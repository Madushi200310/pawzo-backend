<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAnnouncement;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAnnouncementsTest extends TestCase
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

        $this->admin = $this->user(['role' => 'admin']);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_replace([
            'role' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'request_id' => (string) Str::uuid(),
            'title' => 'Scheduled maintenance',
            'body' => 'Pawzo will undergo scheduled maintenance.',
            'audience' => 'all',
        ], $overrides);
    }

    public function test_guests_and_regular_users_are_blocked(): void
    {
        $this->getJson('/api/admin/announcements')
            ->assertUnauthorized();

        $this->postJson('/api/admin/announcements', $this->payload())
            ->assertUnauthorized();

        Sanctum::actingAs($this->user());

        $this->getJson('/api/admin/announcements')
            ->assertForbidden();

        $this->postJson('/api/admin/announcements', $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('admin_announcements', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_inactive_and_unverified_admins_are_blocked(): void
    {
        foreach ([
            ['is_active' => false],
            ['email_verified_at' => null],
        ] as $attributes) {
            Sanctum::actingAs(
                $this->user(array_merge(['role' => 'admin'], $attributes))
            );

            $this->postJson(
                '/api/admin/announcements',
                $this->payload(),
            )->assertForbidden();
        }

        $this->assertDatabaseCount('admin_announcements', 0);
    }

    public function test_all_audience_only_receives_active_verified_users(): void
    {
        $eligible = $this->user();
        $inactive = $this->user(['is_active' => false]);
        $unverified = $this->user(['email_verified_at' => null]);
        $deleted = $this->user();
        $deleted->delete();

        Sanctum::actingAs($this->admin);

        $response = $this->postJson(
            '/api/admin/announcements',
            $this->payload(),
        )->assertCreated()
            ->assertJsonPath('data.recipient_count', 2);

        // The publishing admin is also an active verified user.
        foreach ([$this->admin, $eligible] as $user) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $user->id,
                'type' => Notification::TYPE_ADMIN_ANNOUNCEMENT,
                'title' => 'Scheduled maintenance',
                'read_at' => null,
            ]);
        }

        foreach ([$inactive, $unverified, $deleted] as $user) {
            $this->assertDatabaseMissing('notifications', [
                'user_id' => $user->id,
                'type' => Notification::TYPE_ADMIN_ANNOUNCEMENT,
            ]);
        }

        $notification = Notification::where('user_id', $eligible->id)
            ->sole();

        $this->assertSame(
            $response->json('data.id'),
            $notification->data['announcement_id'],
        );

        $this->assertSame(
            $this->admin->id,
            $notification->data['admin_id'],
        );
    }

    public function test_selected_audience_only_sends_to_selected_users(): void
    {
        $selected = $this->user();
        $other = $this->user();

        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/announcements', $this->payload([
            'audience' => 'selected',
            'user_ids' => [$selected->id],
        ]))->assertCreated()
            ->assertJsonPath('data.recipient_count', 1);

        $this->assertDatabaseCount('notifications', 1);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $selected->id,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $other->id,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_same_request_id_does_not_duplicate_notifications(): void
    {
        $selected = $this->user();
        $payload = $this->payload([
            'audience' => 'selected',
            'user_ids' => [$selected->id],
        ]);

        Sanctum::actingAs($this->admin);

        $first = $this->postJson('/api/admin/announcements', $payload)
            ->assertCreated();

        $this->postJson('/api/admin/announcements', $payload)
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertDatabaseCount('admin_announcements', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_request_id_cannot_be_reused_for_changed_content(): void
    {
        $payload = $this->payload();

        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/announcements', $payload)
            ->assertCreated();

        $payload['body'] = 'A different announcement.';

        $this->postJson('/api/admin/announcements', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('request_id');

        $this->assertDatabaseCount('admin_announcements', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_another_admin_cannot_reuse_request_id(): void
    {
        $otherAdmin = $this->user(['role' => 'admin']);
        $payload = $this->payload();

        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/announcements', $payload)
            ->assertCreated();

        Sanctum::actingAs($otherAdmin);

        $this->postJson('/api/admin/announcements', $payload)
            ->assertStatus(409);

        $this->assertDatabaseCount('admin_announcements', 1);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_ineligible_selected_user_rolls_back_publication(): void
    {
        $eligible = $this->user();
        $inactive = $this->user(['is_active' => false]);

        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/announcements', $this->payload([
            'audience' => 'selected',
            'user_ids' => [$eligible->id, $inactive->id],
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('user_ids');

        $this->assertDatabaseCount('admin_announcements', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_invalid_payload_is_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson('/api/admin/announcements', $this->payload([
            'audience' => 'selected',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('user_ids');

        $this->postJson('/api/admin/announcements', $this->payload([
            'title' => '   ',
        ]))->assertUnprocessable();

        $this->postJson('/api/admin/announcements', $this->payload([
            'request_id' => 'invalid',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('request_id');

        $this->assertDatabaseCount('admin_announcements', 0);
    }

    public function test_admin_can_list_published_announcements(): void
    {
        Sanctum::actingAs($this->admin);

        $created = $this->postJson(
            '/api/admin/announcements',
            $this->payload(),
        )->assertCreated();

        $this->getJson('/api/admin/announcements?per_page=10')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $created->json('data.id'));

        $this->assertSame(1, AdminAnnouncement::count());
    }
}