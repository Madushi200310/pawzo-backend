<?php

namespace Tests\Feature;

use App\Models\FoundPetReport;
use App\Models\LostPetReport;
use App\Models\Notification;
use App\Models\PetMatch;
use App\Models\User;
use App\Services\Matching\SmartMatchingService;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SmartMatchingNotificationsTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        if (
            getenv('DB_CONNECTION') !== 'sqlite'
            || getenv('DB_DATABASE') !== ':memory:'
        ) {
            throw new \RuntimeException(
                'Use SQLite :memory: for these tests.'
            );
        }

        if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
            throw new \RuntimeException(
                'Run php artisan config:clear first.'
            );
        }

        parent::setUp();

        Storage::fake('public');

        config([
            'smart_matching.minimum_score' => 55,
            'smart_matching.maximum_days' => 90,
            'smart_matching.maximum_distance_km' => 50,
        ]);
    }

    private function owner(): User
    {
        return User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Insert directly so tests control when matching runs.
     */
    private function report(
        string $kind,
        User $owner,
        array $overrides = [],
    ): LostPetReport|FoundPetReport {
        $id = DB::table($kind.'_pet_reports')->insertGetId(
            array_replace([
                'user_id' => $owner->id,
                'pet_name' => 'Buddy',
                'pet_type' => 'dog',
                'breed' => 'Labrador',
                'gender' => 'male',
                'color' => 'Brown',
                'description' => 'Red collar and white chest mark',
                'distinguishing_features' => 'White chest mark',
                $kind.'_at' => $kind === 'lost'
                    ? '2020-01-01 10:00:00'
                    : '2020-01-02 10:00:00',
                'location_name' => 'Nittambuwa',
                'latitude' => 7.142,
                'longitude' => 80.096,
                'contact_name' => 'Test Reporter',
                'contact_phone' => '0771234567',
                'whatsapp_number' => '+94771234567',
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ], $overrides)
        );

        $class = $kind === 'lost'
            ? LostPetReport::class
            : FoundPetReport::class;

        return $class::findOrFail($id);
    }

    public function test_new_match_notifies_both_owners_with_correct_data(): void
    {
        $lostOwner = $this->owner();
        $foundOwner = $this->owner();
        $unrelatedOwner = $this->owner();

        $lost = $this->report('lost', $lostOwner);
        $found = $this->report('found', $foundOwner);

        $count = app(SmartMatchingService::class)
            ->refresh('lost', $lost->id);

        $this->assertSame(1, $count);
        $this->assertDatabaseCount('pet_matches', 1);
        $this->assertDatabaseCount('notifications', 2);

        $match = PetMatch::firstOrFail();

        foreach ([
            [$lostOwner, 'lost', $lost->id, $found->id],
            [$foundOwner, 'found', $found->id, $lost->id],
        ] as [$owner, $type, $reportId, $candidateId]) {
            $notification = Notification::query()
                ->where('user_id', $owner->id)
                ->where('type', Notification::TYPE_SMART_MATCH)
                ->sole();

            $this->assertSame(
                (int) $reportId,
                $notification->data['report_id']
            );

            $this->assertSame(
                $type,
                $notification->data['report_type']
            );

            $this->assertSame(
                (int) $candidateId,
                $notification->data['candidate_id']
            );

            $this->assertEquals(
                (float) $match->score,
                $notification->data['score']
            );

            $this->assertSame(
                "/api/{$type}-pets/{$reportId}/matches",
                $notification->action_url
            );

            $this->assertNull($notification->read_at);
        }

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $unrelatedOwner->id,
        ]);
    }

    public function test_refreshing_either_side_does_not_duplicate_notifications(): void
    {
        $lost = $this->report('lost', $this->owner());
        $found = $this->report('found', $this->owner());

        $matching = app(SmartMatchingService::class);

        $matching->refresh('lost', $lost->id);

        $originalIds = Notification::orderBy('id')
            ->pluck('id')
            ->all();

        $matchId = PetMatch::firstOrFail()->id;

        $this->assertCount(2, $originalIds);

        $matching->refresh('lost', $lost->id);
        $matching->refresh('found', $found->id);
        $matching->refresh('lost', $lost->id);

        $this->assertDatabaseCount('pet_matches', 1);
        $this->assertDatabaseCount('notifications', 2);

        $this->assertSame(
            $matchId,
            PetMatch::firstOrFail()->id
        );

        $this->assertSame(
            $originalIds,
            Notification::orderBy('id')->pluck('id')->all()
        );
    }

    public function test_same_owner_receives_only_one_notification(): void
    {
        $owner = $this->owner();

        $lost = $this->report('lost', $owner);
        $found = $this->report('found', $owner);

        // Start from the found side to check reverse matching too.
        app(SmartMatchingService::class)
            ->refresh('found', $found->id);

        $this->assertDatabaseCount('pet_matches', 1);
        $this->assertDatabaseCount('notifications', 1);

        $notification = Notification::firstOrFail();

        $this->assertSame(
            (int) $owner->id,
            (int) $notification->user_id
        );

        $this->assertSame(
            'lost',
            $notification->data['report_type']
        );

        $this->assertSame(
            (int) $lost->id,
            $notification->data['report_id']
        );

        $this->assertSame(
            (int) $found->id,
            $notification->data['candidate_id']
        );
    }

    public function test_non_matching_reports_do_not_generate_notifications(): void
    {
        $lost = $this->report('lost', $this->owner());

        $this->report('found', $this->owner(), [
            'pet_type' => 'cat',
        ]);

        $count = app(SmartMatchingService::class)
            ->refresh('lost', $lost->id);

        $this->assertSame(0, $count);
        $this->assertDatabaseCount('pet_matches', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_notification_failure_rolls_back_match_and_previous_notification(): void
    {
        $lost = $this->report('lost', $this->owner());
        $found = $this->report('found', $this->owner());

        // First notification is saved; the second deliberately fails.
        $this->app->instance(
            NotificationService::class,
            new class extends NotificationService {
                private int $calls = 0;

                public function smartMatchFound(
                    User $user,
                    int $reportId,
                    string $reportType,
                    int $candidateId,
                    float $score,
                ): Notification {
                    $this->calls++;

                    if ($this->calls === 2) {
                        throw new \RuntimeException(
                            'Simulated notification failure'
                        );
                    }

                    return parent::smartMatchFound(
                        $user,
                        $reportId,
                        $reportType,
                        $candidateId,
                        $score,
                    );
                }
            }
        );

        $caught = false;

        try {
            app(SmartMatchingService::class)
                ->refresh('lost', $lost->id);
        } catch (\RuntimeException $error) {
            $caught = true;

            $this->assertSame(
                'Simulated notification failure',
                $error->getMessage()
            );
        }

        $this->assertTrue(
            $caught,
            'Expected the notification failure to propagate.'
        );

        $this->assertDatabaseCount('pet_matches', 0);
        $this->assertDatabaseCount('notifications', 0);

        // The already-created reports must remain intact.
        $this->assertDatabaseHas('lost_pet_reports', [
            'id' => $lost->id,
        ]);

        $this->assertDatabaseHas('found_pet_reports', [
            'id' => $found->id,
        ]);
    }
}