<?php

namespace Tests\Feature;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComplaintsTest extends TestCase
{
    use RefreshDatabase;

    private User $reporter;
    private User $target;
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

        if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
            throw new \RuntimeException(
                'Run php artisan config:clear before testing.'
            );
        }

        parent::setUp();

        $this->reporter = $this->user();
        $this->target = $this->user();
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
            'target_type' => 'user',
            'target_id' => $this->target->id,
            'reason' => 'suspicious_activity',
            'description' => 'Please investigate this account.',
        ], $overrides);
    }

    private function complaint(?User $reporter = null): Complaint
    {
        return Complaint::create(array_merge($this->payload(), [
            'reporter_id' => ($reporter ?? $this->reporter)->id,
        ]));
    }

    public function test_guests_cannot_submit_or_read_complaints(): void
    {
        $this->postJson('/api/complaints', $this->payload())
            ->assertUnauthorized();

        $this->getJson('/api/complaints/mine')
            ->assertUnauthorized();

        $this->getJson('/api/admin/complaints')
            ->assertUnauthorized();
    }

    public function test_inactive_and_unverified_users_are_blocked(): void
    {
        foreach ([
            ['is_active' => false],
            ['email_verified_at' => null],
        ] as $attributes) {
            Sanctum::actingAs($this->user($attributes));

            $this->postJson('/api/complaints', $this->payload())
                ->assertForbidden();

            $this->getJson('/api/complaints/mine')
                ->assertForbidden();
        }

        $this->assertDatabaseCount('complaints', 0);
    }

    public function test_verified_user_can_submit_complaint(): void
    {
        Sanctum::actingAs($this->reporter);

        $this->postJson('/api/complaints', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.target_id', $this->target->id);

        $this->assertDatabaseHas('complaints', [
            'reporter_id' => $this->reporter->id,
            'target_type' => 'user',
            'target_id' => $this->target->id,
            'status' => 'pending',
        ]);
    }

    public function test_duplicate_open_complaint_is_blocked(): void
    {
        $complaint = $this->complaint();
        Sanctum::actingAs($this->reporter);

        $this->postJson('/api/complaints', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('target_id');

        $complaint->forceFill(['status' => 'investigating'])->save();

        $this->postJson('/api/complaints', $this->payload())
            ->assertUnprocessable();

        $this->assertDatabaseCount('complaints', 1);
    }

    public function test_mine_is_private_and_hides_internal_admin_notes(): void
    {
        $own = $this->complaint();

        $own->forceFill([
            'admin_notes' => 'Internal investigation details.',
        ])->save();

        $this->complaint($this->user());

        Sanctum::actingAs($this->reporter);

        $response = $this->getJson('/api/complaints/mine')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $own->id);

        $this->assertArrayNotHasKey(
            'admin_notes',
            $response->json('data.0'),
        );
    }

    public function test_user_cannot_access_admin_actions(): void
    {
        $complaint = $this->complaint();
        Sanctum::actingAs($this->reporter);

        $this->getJson('/api/admin/complaints')
            ->assertForbidden();

        $this->getJson("/api/admin/complaints/{$complaint->id}")
            ->assertForbidden();

        $this->patchJson("/api/admin/complaints/{$complaint->id}", [
            'status' => 'resolved',
            'admin_notes' => 'Trying to resolve my own complaint.',
        ])->assertForbidden();

        $this->assertSame('pending', $complaint->fresh()->status);
    }

    public function test_admin_can_filter_and_view_complaint_details(): void
    {
        $complaint = $this->complaint();
        $other = $this->complaint($this->user());
        $other->forceFill(['status' => 'dismissed'])->save();

        Sanctum::actingAs($this->admin);

        $query = http_build_query([
            'status' => 'pending',
            'target_type' => 'user',
            'reason' => 'suspicious_activity',
            'reporter_id' => $this->reporter->id,
        ]);

        $this->getJson('/api/admin/complaints?'.$query)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $complaint->id);

        $this->getJson("/api/admin/complaints/{$complaint->id}")
            ->assertOk()
            ->assertJsonPath(
                'data.reporter.id',
                $this->reporter->id,
            );
    }

    public function test_admin_can_investigate_then_resolve_with_audit_fields(): void
    {
        $complaint = $this->complaint();
        Sanctum::actingAs($this->admin);

        $url = "/api/admin/complaints/{$complaint->id}";

        $this->patchJson($url, [
            'status' => 'investigating',
            'admin_notes' => 'Checking the reported account.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'investigating');

        $this->assertNull($complaint->fresh()->closed_at);

        $this->patchJson($url, [
            'status' => 'resolved',
            'admin_notes' => 'Investigation completed and action recorded.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.reviewed_by', $this->admin->id);

        $this->assertNotNull($complaint->fresh()->reviewed_at);
        $this->assertNotNull($complaint->fresh()->closed_at);

        // Closing a complaint must not automatically deactivate its target.
        $this->assertTrue($this->target->fresh()->is_active);
    }

    public function test_closed_complaint_retry_is_safe_but_changes_are_blocked(): void
    {
        $complaint = $this->complaint();
        Sanctum::actingAs($this->admin);

        $url = "/api/admin/complaints/{$complaint->id}";
        $payload = [
            'status' => 'dismissed',
            'admin_notes' => 'No supporting evidence found.',
        ];

        $this->patchJson($url, $payload)->assertOk();

        $closedAt = $complaint->fresh()->closed_at->toISOString();

        $this->patchJson($url, $payload)->assertOk();

        $this->assertSame(
            $closedAt,
            $complaint->fresh()->closed_at->toISOString(),
        );

        $this->patchJson($url, [
            'status' => 'resolved',
            'admin_notes' => 'Trying to change a closed outcome.',
        ])->assertUnprocessable();

        $this->assertSame('dismissed', $complaint->fresh()->status);
    }

    public function test_invalid_targets_and_injected_moderation_fields_are_blocked(): void
    {
        Sanctum::actingAs($this->reporter);

        $this->postJson('/api/complaints', $this->payload([
            'target_type' => 'unknown',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('target_type');

        $this->postJson('/api/complaints', $this->payload([
            'target_id' => 999999,
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('target_id');

        $this->postJson('/api/complaints', $this->payload([
            'reporter_id' => $this->admin->id,
            'status' => 'resolved',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['reporter_id', 'status']);

        $this->assertDatabaseCount('complaints', 0);
    }

    public function test_admin_notes_are_required_to_close_complaint(): void
    {
        $complaint = $this->complaint();
        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/admin/complaints/{$complaint->id}", [
            'status' => 'resolved',
            'admin_notes' => '   ',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('admin_notes');

        $this->assertSame('pending', $complaint->fresh()->status);
    }
}