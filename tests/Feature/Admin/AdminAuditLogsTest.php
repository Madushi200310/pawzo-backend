<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\Complaint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAuditLogsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $reporter;
    private Complaint $complaint;

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
        $this->reporter = $this->user();

        $this->complaint = Complaint::create([
            'reporter_id' => $this->reporter->id,
            'target_type' => 'user',
            'target_id' => $this->admin->id,
            'reason' => 'suspicious_activity',
            'description' => 'Please investigate this test account.',
        ]);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_replace([
            'role' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ], $attributes));
    }

    private function complaintUrl(): string
    {
        return "/api/admin/complaints/{$this->complaint->id}";
    }

    public function test_successful_admin_action_records_target_and_status(): void
    {
        Sanctum::actingAs($this->admin);

        $this->patchJson($this->complaintUrl(), [
            'status' => 'investigating',
            'admin_notes' => 'Investigation started.',
        ])->assertOk();

        $log = AdminAuditLog::sole();

        $this->assertSame($this->admin->id, $log->admin_id);
        $this->assertSame('PATCH', $log->method);
        $this->assertSame(
            'api/admin/complaints/{complaint}',
            $log->route,
        );
        $this->assertSame(200, $log->response_status);
        $this->assertSame(
            $this->complaint->id,
            $log->targets['complaint']['id'],
        );
        $this->assertSame(
            'Complaint',
            $log->targets['complaint']['model'],
        );
        $this->assertNotNull($log->created_at);
    }

    public function test_validation_failure_records_final_422_response(): void
    {
        Sanctum::actingAs($this->admin);

        $this->patchJson($this->complaintUrl(), [
            'status' => 'resolved',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('admin_audit_logs', [
            'admin_id' => $this->admin->id,
            'method' => 'PATCH',
            'response_status' => 422,
        ]);

        $this->assertSame(
            'pending',
            $this->complaint->fresh()->status,
        );
    }

    public function test_sensitive_payload_and_authorization_header_are_not_logged(): void
    {
        Sanctum::actingAs($this->admin);

        $secrets = [
            'password' => 'PrivatePassword123',
            'code' => '987654',
            'token' => 'private-body-token',
            'admin_notes' => 'Private investigation notes.',
        ];

        $this->withHeaders([
            'Authorization' => 'Bearer private-header-token',
        ])->patchJson($this->complaintUrl(), array_merge(
            $secrets,
            ['status' => 'investigating'],
        ))->assertOk();

        $stored = json_encode(
            AdminAuditLog::sole()->getAttributes(),
            JSON_THROW_ON_ERROR,
        );

        foreach (array_values($secrets) as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }

        $this->assertStringNotContainsString(
            'private-header-token',
            $stored,
        );
    }

    public function test_get_requests_do_not_create_mutation_logs(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/complaints')->assertOk();
        $this->getJson('/api/admin/audit-logs')->assertOk();

        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_guests_and_non_admins_cannot_read_logs(): void
    {
        $this->getJson('/api/admin/audit-logs')
            ->assertUnauthorized();

        Sanctum::actingAs($this->reporter);

        $this->getJson('/api/admin/audit-logs')
            ->assertForbidden();

        $this->patchJson($this->complaintUrl(), [
            'status' => 'resolved',
            'admin_notes' => 'Unauthorized attempt.',
        ])->assertForbidden();

        $this->assertDatabaseCount('admin_audit_logs', 0);
    }

    public function test_admin_can_filter_logs(): void
    {
        Sanctum::actingAs($this->admin);

        $this->patchJson($this->complaintUrl(), [
            'status' => 'investigating',
            'admin_notes' => 'Investigation started.',
        ])->assertOk();

        $query = http_build_query([
            'admin_id' => $this->admin->id,
            'method' => 'PATCH',
            'response_status' => 200,
            'from' => now()->toDateString(),
            'to' => now()->toDateString(),
        ]);

        $this->getJson('/api/admin/audit-logs?'.$query)
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.admin_id', $this->admin->id);

        $this->getJson('/api/admin/audit-logs?response_status=422')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_invalid_filters_are_rejected(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/audit-logs?method=INVALID')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('method');

        $this->getJson(
            '/api/admin/audit-logs?from=2026-10-20&to=2026-10-01'
        )->assertUnprocessable()
            ->assertJsonValidationErrors('to');

        $this->getJson('/api/admin/audit-logs?per_page=100')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }
}