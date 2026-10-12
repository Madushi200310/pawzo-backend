<?php

namespace Tests\Feature\Admin;

use App\Models\HealthRecord;
use App\Models\Pet;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Vaccination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function user(): User
    {
        return User::factory()->create(['role' => 'user']);
    }

    // ---------------- Index ----------------

    public function test_admin_can_list_users(): void
    {
        $admin = $this->admin();
        User::factory()->count(5)->create(['role' => 'user']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonPath('message', 'Users fetched successfully.')
            ->assertJsonStructure(['users' => ['data', 'current_page', 'per_page', 'total']]);
    }

    public function test_non_admin_cannot_list_users(): void
    {
        $user = $this->user();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertForbidden();
    }

    public function test_guest_cannot_list_users(): void
    {
        $this->getJson('/api/admin/users')->assertUnauthorized();
    }

    public function test_admin_can_search_users_by_name(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Alice Wonderland', 'role' => 'user']);
        User::factory()->create(['name' => 'Bob Marley', 'role' => 'user']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users?search=Alice')
            ->assertOk();

        $this->assertCount(1, $response->json('users.data'));
    }

    public function test_admin_can_search_users_by_email(): void
    {
        $admin = $this->admin();
        User::factory()->create(['email' => 'unique@example.com', 'role' => 'user']);
        User::factory()->create(['email' => 'other@example.com', 'role' => 'user']);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users?search=unique')
            ->assertOk();

        $this->assertCount(1, $response->json('users.data'));
    }

    public function test_admin_can_filter_by_active_status(): void
    {
        $admin = $this->admin();
        User::factory()->count(2)->create(['is_active' => true, 'role' => 'user']);
        User::factory()->count(3)->create(['is_active' => false, 'role' => 'user']);

        $activeRes = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users?status=active')->assertOk();
        $inactiveRes = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users?status=inactive')->assertOk();

        $this->assertCount(3, $activeRes->json('users.data'));   // 2 users + 1 admin
        $this->assertCount(3, $inactiveRes->json('users.data')); // 3 inactive
    }

    public function test_admin_does_not_see_soft_deleted_users_by_default(): void
    {
        $admin = $this->admin();
        $user  = $this->user();
        $user->delete();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertOk();

        $ids = collect($response->json('users.data'))->pluck('id');
        $this->assertNotContains($user->id, $ids);
    }

    public function test_admin_can_include_soft_deleted_users(): void
    {
        $admin = $this->admin();
        $user  = $this->user();
        $user->delete();

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/users?with_trashed=1')
            ->assertOk();

        $ids = collect($response->json('users.data'))->pluck('id');
        $this->assertContains($user->id, $ids);
    }

    // ---------------- Show ----------------

    public function test_admin_can_view_user_with_summary(): void
    {
        $admin = $this->admin();
        $user  = $this->user();
        $pet   = Pet::factory()->create(['user_id' => $user->id]);
        HealthRecord::factory()->count(2)->create(['pet_id' => $pet->id]);
        Vaccination::factory()->count(3)->create(['pet_id' => $pet->id]);
        Reminder::factory()->count(4)->create([
            'pet_id'  => $pet->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('summary.pets', 1)
            ->assertJsonPath('summary.health_records', 2)
            ->assertJsonPath('summary.vaccinations', 3)
            ->assertJsonPath('summary.reminders', 4);
    }

    // ---------------- UpdateStatus ----------------

    public function test_admin_can_deactivate_user(): void
    {
        $admin = $this->admin();
        $user  = $this->user();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/users/{$user->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('user.is_active', false);
    }

    public function test_admin_can_activate_user(): void
    {
        $admin = $this->admin();
        $user  = User::factory()->create(['is_active' => false, 'role' => 'user']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/users/{$user->id}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('user.is_active', true);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/users/{$admin->id}/status", ['is_active' => false])
            ->assertStatus(422);
    }

    public function test_admin_cannot_deactivate_another_admin(): void
    {
        $admin  = $this->admin();
        $admin2 = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/users/{$admin2->id}/status", ['is_active' => false])
            ->assertStatus(422);
    }

    public function test_status_requires_boolean(): void
    {
        $admin = $this->admin();
        $user  = $this->user();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/users/{$user->id}/status", ['is_active' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);
    }

    // ---------------- Destroy ----------------

    public function test_admin_can_soft_delete_user(): void
    {
        $admin = $this->admin();
        $user  = $this->user();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/users/{$user->id}")
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/users/{$admin->id}")
            ->assertStatus(422);
    }

    public function test_admin_cannot_delete_another_admin(): void
    {
        $admin  = $this->admin();
        $admin2 = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/users/{$admin2->id}")
            ->assertStatus(422);
    }

    // ---------------- Activity ----------------

    public function test_admin_can_view_user_activity(): void
    {
        $admin = $this->admin();
        $user  = $this->user();
        $pet   = Pet::factory()->create(['user_id' => $user->id]);
        Reminder::factory()->count(3)->create([
            'pet_id'  => $pet->id,
            'user_id' => $user->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/admin/users/{$user->id}/activity")
            ->assertOk()
            ->assertJsonPath('summary.pets', 1)
            ->assertJsonPath('summary.reminders', 3)
            ->assertJsonStructure([
                'recent' => ['pets', 'reminders'],
            ]);
    }
}