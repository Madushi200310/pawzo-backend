<?php

namespace Tests\Feature;

use App\Models\FoundPetReport;
use App\Models\LostPetReport;
use App\Models\PetMatch;
use App\Models\User;
use App\Services\Matching\SmartMatchingService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SmartMatchingTest extends TestCase
{
    // Real commits are required to test after-commit observers.
    use DatabaseMigrations;

    protected function setUp(): void
    {
        // Prevent these tests from using the development database.
        if (
            getenv('DB_CONNECTION') !== 'sqlite'
            || getenv('DB_DATABASE') !== ':memory:'
        ) {
            throw new \RuntimeException(
                'Use phpunit.smart-matching.xml with SQLite :memory:.'
            );
        }

        if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
            throw new \RuntimeException(
                'Run php artisan config:clear first.'
            );
        }

        parent::setUp();

        Storage::fake('public');

        $this->assertTrue(
            extension_loaded('gd'),
            'Enable GD before running the matching tests.'
        );
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'pet.png',
            file_get_contents(
                base_path('tests/Fixtures/LostPets/pet.png')
            )
        );
    }

    private function report(
        string $kind,
        array $overrides = []
    ): LostPetReport|FoundPetReport {
        $user = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $data = array_replace([
            'pet_type' => 'dog',
            'breed' => 'Labrador',
            'gender' => 'male',
            'color' => 'Brown',
            'description' => 'Red collar and white chest mark',
            'distinguishing_features' => 'White chest mark',

            $kind.'_at' => $kind === 'lost'
                ? '2020-01-01T10:00:00+05:30'
                : '2020-01-02T10:00:00+05:30',

            'location_name' => 'Nittambuwa',
            'latitude' => 7.142,
            'longitude' => 80.096,
            'contact_name' => 'Test Reporter',
            'contact_phone' => '0771234567',
            'whatsapp_number' => '+94771234567',
            'photos' => [$this->photo()],
        ], $overrides);

        $response = $this->postJson(
            '/api/'.$kind.'-pets',
            $data
        )->assertCreated();

        $class = $kind === 'lost'
            ? LostPetReport::class
            : FoundPetReport::class;

        return $class::findOrFail($response->json('data.id'));
    }

    private function asOwner(
        LostPetReport|FoundPetReport $report
    ): void {
        Sanctum::actingAs($report->user);
    }

    public function test_creating_either_side_generates_a_unique_match_without_a_manual_refresh(): void
    {
        $found = $this->report('found');
        $lost = $this->report('lost');

        $this->assertDatabaseCount('pet_matches', 1);

        $id = PetMatch::firstOrFail()->id;

        app(SmartMatchingService::class)->refresh('lost', $lost->id);

        $this->assertSame($id, PetMatch::firstOrFail()->id);
        $this->assertDatabaseCount('pet_matches', 1);

        $this->asOwner($lost);

        $this->getJson('/api/lost-pets/'.$lost->id.'/matches')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.score_is_probability', false)
            ->assertJsonPath('data.0.candidate.id', $found->id)
            ->assertJsonPath(
                'data.0.details.breakdown.photos.points',
                0
            );
    }

    public function test_found_owner_can_read_reverse_suggestions(): void
    {
        $lost = $this->report('lost');
        $found = $this->report('found');

        $this->asOwner($found);

        $this->getJson('/api/found-pets/'.$found->id.'/matches')
            ->assertOk()
            ->assertJsonPath('data.0.candidate_type', 'lost')
            ->assertJsonPath('data.0.candidate.id', $lost->id);
    }

    public function test_guests_and_other_reporters_cannot_access_or_refresh_suggestions(): void
    {
        $this->getJson('/api/lost-pets/1/matches')
            ->assertUnauthorized();

        $lost = $this->report('lost');

        // Authenticate as a different report creator.
        $this->report('found');

        $this->getJson('/api/lost-pets/'.$lost->id.'/matches')
            ->assertForbidden();

        $this->postJson(
            '/api/lost-pets/'.$lost->id.'/matches/refresh'
        )->assertForbidden();
    }

    public function test_unverified_and_inactive_reporters_are_blocked(): void
    {
        $lost = $this->report('lost');
        $user = $lost->user;

        // email_verified_at is intentionally not mass assignable.
        // forceFill changes this test fixture without changing User::$fillable.
        $user->forceFill([
            'email_verified_at' => null,
        ])->save();

        $this->assertNull(
            $user->fresh()->email_verified_at
        );

        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/lost-pets/'.$lost->id.'/matches')
            ->assertForbidden();

        // Restore verification, then test an inactive account.
        $user->forceFill([
            'email_verified_at' => now(),
            'is_active' => false,
        ])->save();

        $this->assertNotNull(
            $user->fresh()->email_verified_at
        );

        $this->assertFalse(
            (bool) $user->fresh()->is_active
        );

        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/lost-pets/'.$lost->id.'/matches')
            ->assertForbidden();
    }

    public function test_different_species_and_conflicting_known_genders_are_excluded(): void
    {
        $this->report('lost');

        $this->report('found', [
            'pet_type' => 'cat',
        ]);

        $this->report('found', [
            'gender' => 'female',
        ]);

        $this->assertDatabaseCount('pet_matches', 0);
    }

    public function test_unknown_breed_and_gender_do_not_receive_agreement_points(): void
    {
        $this->report('lost');

        $this->report('found', [
            'breed' => null,
            'gender' => 'unknown',
        ]);

        $match = PetMatch::firstOrFail();

        $this->assertEquals(
            0,
            $match->details['breakdown']['breed']['points']
        );

        $this->assertEquals(
            0,
            $match->details['breakdown']['gender']['points']
        );

        $this->assertLessThan(100, $match->score);
    }

    public function test_wrong_date_order_and_reports_beyond_the_date_window_are_excluded(): void
    {
        $this->report('lost');

        $this->report('found', [
            'found_at' => '2019-12-31T10:00:00+05:30',
        ]);

        $this->report('found', [
            'found_at' => '2020-05-01T10:00:00+05:30',
        ]);

        $this->assertDatabaseCount('pet_matches', 0);
    }

    public function test_far_locations_and_low_scoring_candidates_are_excluded(): void
    {
        $this->report('lost');

        $this->report('found', [
            'latitude' => 9.66,
            'longitude' => 80.02,
        ]);

        $this->report('found', [
            'breed' => 'Poodle',
            'color' => 'Black',
            'gender' => 'unknown',
            'description' => 'Curly fur',
            'distinguishing_features' => 'Short tail',
        ]);

        $this->assertDatabaseCount('pet_matches', 0);
    }

    public function test_status_changes_remove_and_recreate_suggestions_without_changing_the_other_report(): void
    {
        $lost = $this->report('lost');
        $found = $this->report('found');

        $this->asOwner($found);

        $url = '/api/found-pets/'.$found->id.'/status';

        $this->patchJson($url, [
            'status' => 'reunited',
        ])->assertOk();

        $this->assertDatabaseCount('pet_matches', 0);
        $this->assertSame('open', $lost->fresh()->status);

        $this->patchJson($url, [
            'status' => 'open',
        ])->assertOk();

        $this->assertDatabaseCount('pet_matches', 1);
    }

    public function test_location_edits_refresh_existing_suggestions(): void
    {
        $this->report('lost');
        $found = $this->report('found');

        $this->patchJson('/api/found-pets/'.$found->id, [
            'latitude' => 9.66,
            'longitude' => 80.02,
        ])->assertOk();

        $this->assertDatabaseCount('pet_matches', 0);
    }

    public function test_deactivation_hides_a_cached_candidate_and_deletion_cascades(): void
    {
        $lost = $this->report('lost');
        $found = $this->report('found');

        $found->user->update([
            'is_active' => false,
        ]);

        $this->asOwner($lost);

        $this->getJson('/api/lost-pets/'.$lost->id.'/matches')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->deleteJson('/api/lost-pets/'.$lost->id)
            ->assertOk();

        $this->assertDatabaseCount('pet_matches', 0);
    }

    public function test_refresh_endpoint_and_pagination_validation(): void
    {
        $lost = $this->report('lost');
        $this->report('found');

        $this->asOwner($lost);

        $this->postJson(
            '/api/lost-pets/'.$lost->id.'/matches/refresh'
        )
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->getJson(
            '/api/lost-pets/'.$lost->id.'/matches?per_page=51'
        )->assertUnprocessable();
    }

    public function test_observer_does_not_publish_rolled_back_changes(): void
    {
        $lost = $this->report('lost');
        $this->report('found');

        DB::beginTransaction();

        try {
            $lost->status = 'closed';
            $lost->save();
        } finally {
            DB::rollBack();
        }

        $this->assertSame('open', $lost->fresh()->status);
        $this->assertDatabaseCount('pet_matches', 1);
    }

    public function test_usable_photos_are_seen_after_upload_commit_and_after_photo_removal(): void
    {
        $image = imagecreatetruecolor(128, 128);

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $value = ($x + $y) % 2 === 0 ? 25 : 225;

                imagefilledrectangle(
                    $image,
                    $x * 16,
                    $y * 16,
                    $x * 16 + 15,
                    $y * 16 + 15,
                    imagecolorallocate(
                        $image,
                        $value,
                        $value,
                        $value
                    )
                );
            }
        }

        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        unset($image);

        $this->report('lost', [
            'photos' => [
                UploadedFile::fake()->createWithContent(
                    'one.png',
                    $bytes
                ),
            ],
        ]);

        $found = $this->report('found');

        $this->assertNull(
            PetMatch::firstOrFail()->details['photo_similarity']
        );

        $response = $this->postJson(
            '/api/found-pets/'.$found->id.'/photos',
            [
                'photos' => [
                    UploadedFile::fake()->createWithContent(
                        'two.png',
                        $bytes
                    ),
                ],
            ]
        )->assertOk();

        $this->assertEquals(
            1,
            PetMatch::firstOrFail()->details['photo_similarity']
        );

        $this->assertEquals(
            5,
            PetMatch::firstOrFail()
                ->details['breakdown']['photos']['points']
        );

        $photoId = $response->json('data.photos.1.id');

        $this->deleteJson(
            '/api/found-pets/'.$found->id.'/photos/'.$photoId
        )->assertOk();

        $this->assertNull(
            PetMatch::firstOrFail()->details['photo_similarity']
        );
    }

    public function test_missing_photo_files_do_not_break_metadata_matching(): void
    {
        $lost = $this->report('lost');
        $found = $this->report('found');

        Storage::disk('public')->delete(
            $found->photos()->first()->path
        );

        $this->assertSame(
            1,
            app(SmartMatchingService::class)->refresh(
                'lost',
                $lost->id
            )
        );

        $this->assertNull(
            PetMatch::firstOrFail()->details['photo_similarity']
        );
    }

    public function test_matching_failure_does_not_undo_a_committed_report_or_delete_its_photo(): void
    {
        $this->mock(
            SmartMatchingService::class,
            function ($mock) {
                $mock->shouldReceive('refresh')
                    ->once()
                    ->andThrow(
                        new \RuntimeException(
                            'Simulated matching failure'
                        )
                    );
            }
        );

        $lost = $this->report('lost');

        $this->assertDatabaseHas('lost_pet_reports', [
            'id' => $lost->id,
        ]);

        Storage::disk('public')->assertExists(
            $lost->photos()->first()->path
        );
    }
}