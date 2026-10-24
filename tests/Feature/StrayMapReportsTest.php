<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StrayMapReportsTest extends TestCase
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

        Sanctum::actingAs($this->viewer);
    }

    private function endpoint(array $overrides = []): string
    {
        return '/api/map/reports?'.http_build_query(
            array_replace([
                'latitude' => 7.142,
                'longitude' => 80.096,
                'kind' => 'stray',
            ], $overrides)
        );
    }

    private function report(
        string $kind = 'stray',
        array $overrides = [],
    ): int {
        $table = match ($kind) {
            'lost' => 'lost_pet_reports',
            'found' => 'found_pet_reports',
            'stray' => 'stray_reports',
        };

        $dateColumn = match ($kind) {
            'lost' => 'lost_at',
            'found' => 'found_at',
            'stray' => 'spotted_at',
        };

        $data = [
            'user_id' => $this->viewer->id,
            'pet_name' => 'Map Test',
            'pet_type' => 'dog',
            'gender' => 'unknown',
            'color' => 'Brown',
            'description' => 'Animal seen near Nittambuwa',
            $dateColumn => '2020-01-02 10:00:00',
            'location_name' => 'Nittambuwa',
            'latitude' => 7.142,
            'longitude' => 80.096,
            'contact_name' => 'Private Reporter',
            'contact_phone' => '0771234567',
            'whatsapp_number' => '+94771234567',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($kind === 'stray') {
            $data['animal_condition'] = 'injured';
        }

        return (int) DB::table($table)->insertGetId(
            array_replace($data, $overrides)
        );
    }

    public function test_stray_pin_uses_correct_date_url_and_private_fields_are_hidden(): void
    {
        $id = $this->report();

        $response = $this->getJson($this->endpoint())
            ->assertOk()
            ->assertHeader('Content-Type', 'application/geo+json')
            ->assertJsonPath('type', 'FeatureCollection')
            ->assertJsonCount(1, 'features')
            ->assertJsonPath('features.0.id', 'stray:'.$id)
            ->assertJsonPath('features.0.properties.kind', 'stray')
            ->assertJsonPath(
                'features.0.properties.occurred_at',
                '2020-01-02T10:00:00.000000Z'
            )
            ->assertJsonPath(
                'features.0.properties.details_url',
                url('/api/stray-reports/'.$id)
            );

        $feature = $response->json('features.0');

        $this->assertEquals(
            [80.096, 7.142],
            $feature['geometry']['coordinates']
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
            $this->assertArrayNotHasKey(
                $field,
                $feature['properties']
            );
        }
    }

    public function test_all_kind_includes_lost_found_and_stray_with_unique_pin_ids(): void
    {
        $lostId = $this->report('lost');
        $foundId = $this->report('found');
        $strayId = $this->report();

        $response = $this->getJson(
            $this->endpoint(['kind' => 'all'])
        )
            ->assertOk()
            ->assertJsonCount(3, 'features')
            ->assertJsonPath('meta.total_within_radius', 3);

        $ids = array_column($response->json('features'), 'id');

        $this->assertContains('lost:'.$lostId, $ids);
        $this->assertContains('found:'.$foundId, $ids);
        $this->assertContains('stray:'.$strayId, $ids);
        $this->assertCount(3, array_unique($ids));

        $this->getJson($this->endpoint())
            ->assertOk()
            ->assertJsonCount(1, 'features')
            ->assertJsonPath('features.0.id', 'stray:'.$strayId);
    }

    public function test_stray_status_and_pet_type_filters_work(): void
    {
        $this->report();

        $rescuedCat = $this->report('stray', [
            'status' => 'rescued',
            'pet_type' => 'cat',
        ]);

        $this->getJson($this->endpoint())
            ->assertOk()
            ->assertJsonCount(1, 'features');

        $this->getJson($this->endpoint([
            'status' => 'rescued',
            'pet_type' => 'cat',
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'features')
            ->assertJsonPath(
                'features.0.id',
                'stray:'.$rescuedCat
            );

        $this->getJson($this->endpoint([
            'status' => 'rescued',
            'pet_type' => 'dog',
        ]))
            ->assertOk()
            ->assertJsonCount(0, 'features');
    }

    public function test_distant_and_ineligible_reporters_stray_reports_are_hidden(): void
    {
        $visible = $this->report();

        $this->report('stray', [
            'latitude' => 9.66,
            'longitude' => 80.02,
        ]);

        $inactive = User::factory()->create([
            'is_active' => false,
            'email_verified_at' => now(),
        ]);

        $unverified = User::factory()->unverified()->create([
            'is_active' => true,
        ]);

        $this->report('stray', ['user_id' => $inactive->id]);
        $this->report('stray', ['user_id' => $unverified->id]);

        $this->getJson($this->endpoint())
            ->assertOk()
            ->assertJsonCount(1, 'features')
            ->assertJsonPath('features.0.id', 'stray:'.$visible);
    }
}