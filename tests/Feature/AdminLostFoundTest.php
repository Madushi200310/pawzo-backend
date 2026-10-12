<?php

namespace Tests\Feature;

use App\Models\FoundPetReport;
use App\Models\LostPetReport;
use App\Models\PetMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminLostFoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('Use phpunit.admin-lost-found.xml with SQLite :memory:.');
        }
        if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
            throw new \RuntimeException('Run php artisan config:clear before these tests.');
        }
        parent::setUp();
        Storage::fake('public');
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

    private function makeLostReport(array $overrides = []): LostPetReport
    {
        $owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);

        $status = $overrides['status'] ?? 'open';
        unset($overrides['status']);

        $report = new LostPetReport(array_merge([
            'pet_name' => 'Bruno',
            'pet_type' => 'dog',
            'breed' => 'Labrador',
            'gender' => 'male',
            'color' => 'Brown',
            'description' => 'Lost dog in Colombo',
            'lost_at' => now()->subDay(),
            'location_name' => 'Colombo 07',
            'latitude' => 6.9125,
            'longitude' => 79.8487,
            'contact_name' => 'Owner',
            'contact_phone' => '0771234567',
        ], $overrides));

        $report->user()->associate($owner);
        $report->status = $status;   // bypass mass-assignment
        $report->save();

        return $report;
    }

    private function makeFoundReport(array $overrides = []): FoundPetReport
    {
        $owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);

        $status = $overrides['status'] ?? 'open';
        unset($overrides['status']);

        $report = new FoundPetReport(array_merge([
            'pet_name' => 'Found dog',
            'pet_type' => 'dog',
            'breed' => 'Labrador',
            'gender' => 'unknown',
            'color' => 'Brown',
            'description' => 'Found dog in Colombo',
            'found_at' => now()->subDay(),
            'location_name' => 'Colombo 07',
            'latitude' => 6.9125,
            'longitude' => 79.8487,
            'contact_name' => 'Finder',
            'contact_phone' => '0777654321',
        ], $overrides));

        $report->user()->associate($owner);
        $report->status = $status;
        $report->save();

        return $report;
    }

    private function makeMatch(array $overrides = []): PetMatch
    {
        $lostId = DB::table('lost_pet_reports')->insertGetId([
            'user_id' => User::factory()->create()->id,
            'pet_name' => 'X', 'pet_type' => 'dog', 'gender' => 'male',
            'color' => 'brown', 'description' => 'x',
            'lost_at' => now()->subDay(), 'location_name' => 'x',
            'latitude' => 6.9, 'longitude' => 79.8,
            'contact_name' => 'x', 'contact_phone' => '0771234567',
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $foundId = DB::table('found_pet_reports')->insertGetId([
            'user_id' => User::factory()->create()->id,
            'pet_name' => 'Y', 'pet_type' => 'dog', 'gender' => 'unknown',
            'color' => 'brown', 'description' => 'y',
            'found_at' => now()->subDay(), 'location_name' => 'y',
            'latitude' => 6.9, 'longitude' => 79.8,
            'contact_name' => 'y', 'contact_phone' => '0777654321',
            'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $matchId = DB::table('pet_matches')->insertGetId([
            'lost_pet_report_id' => $lostId,
            'found_pet_report_id' => $foundId,
            'score' => $overrides['score'] ?? 85.5,
            'details' => json_encode(['breakdown' => 'test']),
            'lost_updated_at' => now(),
            'found_updated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return PetMatch::findOrFail($matchId);
    }

    // ============================================================
    // Authorization
    // ============================================================

    public function test_non_admin_cannot_access_any_admin_endpoint(): void
    {
        $lost = $this->makeLostReport();
        $found = $this->makeFoundReport();
        $match = $this->makeMatch();

        $this->login();

        $this->getJson('/api/admin/lost-pets')->assertForbidden();
        $this->getJson('/api/admin/lost-pets/'.$lost->id)->assertForbidden();
        $this->deleteJson('/api/admin/lost-pets/'.$lost->id)->assertForbidden();
        $this->patchJson('/api/admin/lost-pets/'.$lost->id.'/flag', ['reason' => 'x'])->assertForbidden();

        $this->getJson('/api/admin/found-pets')->assertForbidden();
        $this->getJson('/api/admin/found-pets/'.$found->id)->assertForbidden();
        $this->deleteJson('/api/admin/found-pets/'.$found->id)->assertForbidden();

        $this->getJson('/api/admin/matches')->assertForbidden();
        $this->getJson('/api/admin/matches/'.$match->id)->assertForbidden();
        $this->patchJson('/api/admin/matches/'.$match->id.'/flag', ['reason' => 'x'])->assertForbidden();
    }

    public function test_guests_cannot_access_admin_endpoints(): void
    {
        $this->getJson('/api/admin/lost-pets')->assertUnauthorized();
        $this->getJson('/api/admin/found-pets')->assertUnauthorized();
        $this->getJson('/api/admin/matches')->assertUnauthorized();
    }

    // ============================================================
    // Lost Pets moderation
    // ============================================================

    public function test_admin_can_list_lost_pets(): void
    {
        $this->makeLostReport(['pet_name' => 'Bruno']);
        $this->makeLostReport(['pet_name' => 'Rex']);

        $this->admin();
        $this->getJson('/api/admin/lost-pets')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_admin_lost_pets_filter_by_q_and_status(): void
    {
        $bruno = $this->makeLostReport(['pet_name' => 'Bruno', 'status' => 'open']);
        $rex = $this->makeLostReport(['pet_name' => 'Rex', 'status' => 'reunited']);

        $brunoId = $bruno->fresh()->id;
        $rexId = $rex->fresh()->id;

        $this->admin();

        $response = $this->getJson('/api/admin/lost-pets?q=bruno')->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($brunoId, $response->json('data.0.id'));

        $response = $this->getJson('/api/admin/lost-pets?status=reunited')->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($rexId, $response->json('data.0.id'));
    }

    public function test_admin_can_show_lost_pet(): void
    {
        $lost = $this->makeLostReport();

        $this->admin();
        $this->getJson('/api/admin/lost-pets/'.$lost->id)
            ->assertOk()
            ->assertJsonPath('data.id', $lost->id);
    }

    public function test_admin_can_delete_lost_pet(): void
    {
        $lost = $this->makeLostReport();

        $this->admin();
        $this->deleteJson('/api/admin/lost-pets/'.$lost->id)->assertOk();

        $this->assertDatabaseMissing('lost_pet_reports', ['id' => $lost->id]);
    }

    public function test_admin_can_flag_and_unflag_lost_pet(): void
    {
        $lost = $this->makeLostReport();

        $this->admin();
        $this->patchJson('/api/admin/lost-pets/'.$lost->id.'/flag', [
            'reason' => 'Fake post',
        ])->assertOk()->assertJsonPath('data.flag_reason', 'Fake post');

        $this->assertNotNull($lost->fresh()->flagged_at);

        $this->patchJson('/api/admin/lost-pets/'.$lost->id.'/unflag')->assertOk();
        $this->assertNull($lost->fresh()->flagged_at);
    }

    public function test_admin_can_filter_flagged_lost_pets(): void
    {
        $flagged = $this->makeLostReport();
        $this->makeLostReport();

        $this->admin();
        $this->patchJson('/api/admin/lost-pets/'.$flagged->id.'/flag', ['reason' => 'x'])->assertOk();

        $response = $this->getJson('/api/admin/lost-pets?flagged=1')->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($flagged->id, $response->json('data.0.id'));
    }

    // ============================================================
    // Found Pets moderation
    // ============================================================

    public function test_admin_can_list_and_delete_found_pets(): void
    {
        $found = $this->makeFoundReport();

        $this->admin();
        $this->getJson('/api/admin/found-pets')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->deleteJson('/api/admin/found-pets/'.$found->id)->assertOk();
        $this->assertDatabaseMissing('found_pet_reports', ['id' => $found->id]);
    }

    public function test_admin_can_flag_and_unflag_found_pet(): void
    {
        $found = $this->makeFoundReport();

        $this->admin();
        $this->patchJson('/api/admin/found-pets/'.$found->id.'/flag', [
            'reason' => 'Scam',
        ])->assertOk()->assertJsonPath('data.flag_reason', 'Scam');

        $this->patchJson('/api/admin/found-pets/'.$found->id.'/unflag')->assertOk();
        $this->assertNull($found->fresh()->flagged_at);
    }

    // ============================================================
    // Match monitoring
    // ============================================================

    public function test_admin_can_list_matches_and_filter_by_score(): void
    {
        $this->makeMatch(['score' => 45.0]);
        $good = $this->makeMatch(['score' => 90.0]);

        $this->admin();
        $this->getJson('/api/admin/matches')
            ->assertOk()
            ->assertJsonPath('total', 2);

        $this->getJson('/api/admin/matches?min_score=80')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $good->id);
    }

    public function test_admin_can_show_match_breakdown(): void
    {
        $match = $this->makeMatch();

        $this->admin();
        $this->getJson('/api/admin/matches/'.$match->id)
            ->assertOk()
            ->assertJsonPath('data.id', $match->id)
            ->assertJsonPath('data.score', 85.5);
    }

    public function test_admin_can_flag_and_unflag_match(): void
    {
        $match = $this->makeMatch();

        $this->admin();
        $this->patchJson('/api/admin/matches/'.$match->id.'/flag', [
            'reason' => 'Photos do not match',
        ])->assertOk()->assertJsonPath('data.flag_reason', 'Photos do not match');

        $this->assertNotNull($match->fresh()->flagged_at);

        $this->patchJson('/api/admin/matches/'.$match->id.'/unflag')->assertOk();
        $this->assertNull($match->fresh()->flagged_at);
    }

    public function test_admin_can_filter_flagged_matches(): void
    {
        $flagged = $this->makeMatch(['score' => 70.0]);
        $this->makeMatch(['score' => 80.0]);

        $this->admin();
        $this->patchJson('/api/admin/matches/'.$flagged->id.'/flag', ['reason' => 'x'])->assertOk();

        $this->getJson('/api/admin/matches?flagged=1')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $flagged->id);
    }

    // ============================================================
    // Validation
    // ============================================================

    public function test_flag_requires_reason(): void
    {
        $lost = $this->makeLostReport();

        $this->admin();
        $this->patchJson('/api/admin/lost-pets/'.$lost->id.'/flag', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_404_on_missing_resources(): void
    {
        $this->admin();
        $this->getJson('/api/admin/lost-pets/999999')->assertNotFound();
        $this->getJson('/api/admin/found-pets/999999')->assertNotFound();
        $this->getJson('/api/admin/matches/999999')->assertNotFound();
    }
}