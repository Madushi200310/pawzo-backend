<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MapReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    protected function setUp(): void
    {
        if (
            getenv('DB_CONNECTION') !== 'sqlite'
            || getenv('DB_DATABASE') !== ':memory:'
        ) {
            throw new \RuntimeException(
                'Use phpunit.maps.xml with SQLite :memory:.'
            );
        }

        if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
            throw new \RuntimeException(
                'Run php artisan config:clear first.'
            );
        }

        parent::setUp();

        $this->viewer = User::factory()->create([
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function endpoint(array $overrides = []): string
    {
        return '/api/map/reports?'.http_build_query(
            array_replace([
                'latitude' => 7.142,
                'longitude' => 80.096,
            ], $overrides)
        );
    }

    private function report(
        string $kind = 'lost',
        array $overrides = []
    ): int {
        // Direct fixtures isolate map reads from matching observers.
        return (int) DB::table($kind.'_pet_reports')->insertGetId(
            array_replace([
                'user_id' => $this->viewer->id,
                'pet_name' => 'Map Test',
                'pet_type' => 'dog',
                'gender' => 'unknown',
                'color' => 'Brown',
                'description' => 'Map test report',
                $kind.'_at' => '2020-01-01 00:00:00',
                'location_name' => 'Nittambuwa',
                'latitude' => 7.142,
                'longitude' => 80.096,
                'contact_name' => 'Private Contact',
                'contact_phone' => '0771234567',
                'whatsapp_number' => '+94771234567',
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ], $overrides)
        );
    }

    public function test_guests_cannot_read_map_reports(): void
    {
        $this->getJson($this->endpoint())
            ->assertUnauthorized();
    }

    public function test_unverified_and_inactive_viewers_are_blocked(): void
    {
        $this->viewer->forceFill([
            'email_verified_at' => null,
        ])->save();

        Sanctum::actingAs($this->viewer->fresh());

        $this->getJson($this->endpoint())
            ->assertForbidden();

        $this->viewer->forceFill([
            'email_verified_at' => now(),
            'is_active' => false,
        ])->save();

        Sanctum::actingAs($this->viewer->fresh());

        $this->getJson($this->endpoint())
            ->assertForbidden();
    }

    public function test_geojson_combines_both_kinds_without_exposing_contact_or_account_fields(): void
    {
        $lostId = $this->report();
        $foundId = $this->report('found');

        Sanctum::actingAs($this->viewer);

        $response = $this->getJson($this->endpoint())
            ->assertOk()
            ->assertHeader('Content-Type', 'application/geo+json')
            ->assertJsonPath('type', 'FeatureCollection')
            ->assertJsonPath('meta.total_within_radius', 2)
            ->assertJsonPath('meta.truncated', false)
            ->assertJsonCount(2, 'features');

        $ids = array_column($response->json('features'), 'id');

        $this->assertContains('lost:'.$lostId, $ids);
        $this->assertContains('found:'.$foundId, $ids);

        foreach ($response->json('features') as $feature) {
            $this->assertSame(
                'Point',
                $feature['geometry']['type']
            );

            $this->assertEquals(
                [80.096, 7.142],
                $feature['geometry']['coordinates']
            );

            $this->assertEquals(
                0,
                $feature['properties']['distance_km']
            );

            $this->assertSame(
                '2020-01-01T00:00:00.000000Z',
                $feature['properties']['occurred_at']
            );

            $properties = $feature['properties'];

            $this->assertSame(
                url(
                    '/api/'.$properties['kind']
                    .'-pets/'.$properties['report_id']
                ),
                $properties['details_url']
            );

            foreach ([
                'user_id',
                'user',
                'email',
                'password',
                'contact_name',
                'contact_phone',
                'whatsapp_number',
            ] as $field) {
                $this->assertArrayNotHasKey($field, $properties);
            }
        }
    }

    public function test_distance_filter_excludes_points_outside_the_circle_and_sorts_both_kinds(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->report('lost', [
            'latitude' => 7.18,
        ]);

        $nearest = $this->report('found');

        // Inside the bounding box, but outside the 10 km circle.
        $this->report('found', [
            'latitude' => 7.222,
            'longitude' => 80.176,
        ]);

        $this->report('lost', [
            'latitude' => 9.66,
        ]);

        $this->getJson($this->endpoint())
            ->assertOk()
            ->assertJsonCount(2, 'features')
            ->assertJsonPath('features.0.id', 'found:'.$nearest);
    }

    public function test_nearest_limit_is_applied_after_all_batches_are_scanned(): void
    {
        Sanctum::actingAs($this->viewer);

        for ($i = 0; $i < 251; $i++) {
            $this->report('lost', [
                'latitude' => 7.18,
            ]);
        }

        // Nearest report is deliberately in a later batch.
        $nearest = $this->report('lost');

        $this->getJson($this->endpoint(['limit' => 1]))
            ->assertOk()
            ->assertJsonPath('features.0.id', 'lost:'.$nearest)
            ->assertJsonCount(1, 'features')
            ->assertJsonPath('meta.total_within_radius', 252)
            ->assertJsonPath('meta.returned', 1)
            ->assertJsonPath('meta.truncated', true);
    }

    public function test_kind_pet_type_and_status_filters(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->report();

        $cat = $this->report('found', [
            'pet_type' => 'cat',
        ]);

        $closed = $this->report('found', [
            'pet_type' => 'cat',
            'status' => 'closed',
        ]);

        $this->getJson($this->endpoint())
            ->assertOk()
            ->assertJsonCount(2, 'features');

        $this->getJson($this->endpoint([
            'kind' => 'found',
            'pet_type' => 'cat',
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'features')
            ->assertJsonPath('features.0.id', 'found:'.$cat);

        $this->getJson($this->endpoint([
            'kind' => 'found',
            'pet_type' => 'cat',
            'status' => 'closed',
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'features')
            ->assertJsonPath('features.0.id', 'found:'.$closed);
    }

    public function test_reports_from_ineligible_accounts_are_hidden_but_other_eligible_reporters_are_visible(): void
    {
        Sanctum::actingAs($this->viewer);

        $inactive = User::factory()->create([
            'is_active' => false,
        ]);

        $unverified = User::factory()->unverified()->create([
            'is_active' => true,
        ]);

        $other = User::factory()->create([
            'is_active' => true,
        ]);

        $this->report('lost', [
            'user_id' => $inactive->id,
        ]);

        $this->report('found', [
            'user_id' => $unverified->id,
        ]);

        $visible = $this->report('found', [
            'user_id' => $other->id,
        ]);

        $this->getJson($this->endpoint())
            ->assertOk()
            ->assertJsonCount(1, 'features')
            ->assertJsonPath('features.0.id', 'found:'.$visible);
    }

    public function test_invalid_filters_return_validation_errors(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->getJson('/api/map/reports')
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'latitude',
                'longitude',
            ]);

        foreach ([
            ['latitude', 91],
            ['latitude', -91],
            ['latitude', 'abc'],
            ['longitude', 181],
            ['longitude', -181],
            ['radius_km', 0],
            ['radius_km', 101],
            ['kind', 'stray'],
            ['pet_type', 'invalid'],
            ['status', 'invalid'],
            ['limit', 0],
            ['limit', 201],
            ['limit', 1.5],
        ] as [$field, $value]) {
            $this->getJson($this->endpoint([$field => $value]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }
    }

    public function test_empty_result_is_a_valid_feature_collection(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->getJson($this->endpoint())
            ->assertOk()
            ->assertJsonPath('features', [])
            ->assertJsonPath('meta.total_within_radius', 0)
            ->assertJsonPath('meta.truncated', false);
    }

    public function test_antimeridian_queries_work_in_both_directions_without_bypassing_status(): void
    {
        Sanctum::actingAs($this->viewer);

        foreach ([179.99, -179.99] as $longitude) {
            $this->report('lost', [
                'latitude' => 0,
                'longitude' => $longitude,
            ]);

            $this->report('found', [
                'latitude' => 0,
                'longitude' => $longitude,
                'status' => 'closed',
            ]);
        }

        foreach ([179.99, -179.99] as $longitude) {
            $this->getJson($this->endpoint([
                'latitude' => 0,
                'longitude' => $longitude,
                'radius_km' => 5,
            ]))
                ->assertOk()
                ->assertJsonCount(2, 'features');
        }
    }

    public function test_poles_and_zero_coordinates_are_supported(): void
    {
        Sanctum::actingAs($this->viewer);

        $this->report('lost', [
            'latitude' => 89.999,
            'longitude' => 150,
        ]);

        $this->report('found', [
            'latitude' => -89.999,
            'longitude' => -150,
        ]);

        foreach ([90, -90] as $latitude) {
            $this->getJson($this->endpoint([
                'latitude' => $latitude,
                'longitude' => 0,
                'radius_km' => 1,
            ]))
                ->assertOk()
                ->assertJsonCount(1, 'features');
        }

        $this->report('found', [
            'latitude' => 0,
            'longitude' => 0,
        ]);

        $this->getJson($this->endpoint([
            'latitude' => 0,
            'longitude' => 0,
            'radius_km' => 0.1,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'features');
    }

    public function test_radius_threshold_and_read_only_behavior(): void
    {
        Sanctum::actingAs($this->viewer);

        $inside = $this->report('lost', [
            'latitude' => 0.0089,
            'longitude' => 0,
        ]);

        $this->report('lost', [
            'latitude' => 0.0091,
            'longitude' => 0,
        ]);

        $before = DB::table('lost_pet_reports')
            ->orderBy('id')
            ->get()
            ->toJson();

        $matchCount = DB::table('pet_matches')->count();

        $this->getJson($this->endpoint([
            'latitude' => 0,
            'longitude' => 0,
            'radius_km' => 1,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'features')
            ->assertJsonPath('features.0.id', 'lost:'.$inside);

        $this->assertSame(
            $before,
            DB::table('lost_pet_reports')
                ->orderBy('id')
                ->get()
                ->toJson()
        );

        $this->assertSame(
            $matchCount,
            DB::table('pet_matches')->count()
        );
    }
}