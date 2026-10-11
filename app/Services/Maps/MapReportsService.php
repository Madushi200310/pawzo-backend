<?php

namespace App\Services\Maps;

use App\Models\FoundPetReport;
use App\Models\LostPetReport;
use Illuminate\Database\Eloquent\Builder;

class MapReportsService
{
    private const EARTH_RADIUS_KM = 6371.0088;

    public function search(array $filters): array
    {
        $latitude = (float) $filters['latitude'];
        $longitude = (float) $filters['longitude'];
        $radius = (float) ($filters['radius_km'] ?? 10);
        $kind = $filters['kind'] ?? 'all';
        $status = $filters['status'] ?? 'open';
        $limit = (int) ($filters['limit'] ?? 100);

        $nearest = [];
        $total = 0;

        $models = [
            'lost' => LostPetReport::class,
            'found' => FoundPetReport::class,
        ];

        foreach ($models as $type => $model) {
            if ($kind !== 'all' && $kind !== $type) {
                continue;
            }

            $dateColumn = $type.'_at';

            $query = $model::query()
                ->select([
                    'id',
                    'pet_name',
                    'pet_type',
                    'status',
                    'location_name',
                    'latitude',
                    'longitude',
                    $dateColumn,
                ])
                ->where('status', $status)
                ->whereHas('user', function (Builder $users) {
                    $users->where('is_active', true)
                        ->whereNotNull('email_verified_at');
                });

            if (isset($filters['pet_type'])) {
                $query->where('pet_type', $filters['pet_type']);
            }

            $this->boundCoordinates(
                $query,
                $latitude,
                $longitude,
                $radius
            );

            // Read candidates in batches.
            // Keep only the nearest requested number in memory.
            // SQL limit must not be applied before distance calculation.
            $query->chunkById(250, function ($reports) use (
                &$nearest,
                &$total,
                $latitude,
                $longitude,
                $radius,
                $limit,
                $type,
                $dateColumn
            ) {
                foreach ($reports as $report) {
                    $distance = $this->distanceKm(
                        $latitude,
                        $longitude,
                        (float) $report->latitude,
                        (float) $report->longitude
                    );

                    if ($distance > $radius) {
                        continue;
                    }

                    $total++;

                    $nearest[] = [
                        'kind' => $type,
                        'id' => (int) $report->id,
                        'distance' => $distance,

                        'feature' => [
                            'type' => 'Feature',

                            // Lost #2 and Found #2 need different pin IDs.
                            'id' => $type.':'.$report->id,

                            'geometry' => [
                                'type' => 'Point',
                                'coordinates' => [
                                    (float) $report->longitude,
                                    (float) $report->latitude,
                                ],
                            ],

                            'properties' => [
                                'report_id' => (int) $report->id,
                                'kind' => $type,
                                'pet_name' => $report->pet_name,
                                'pet_type' => $report->pet_type,
                                'status' => $report->status,
                                'location_name' => $report->location_name,

                                'occurred_at' => $report->{$dateColumn}
                                    ->toISOString(),

                                'distance_km' => round($distance, 3),

                                'details_url' => url(
                                    '/api/'.$type.'-pets/'.$report->id
                                ),
                            ],
                        ],
                    ];
                }

                // Use the original distance for sorting.
                // Break equal-distance ties consistently.
                usort(
                    $nearest,
                    static fn (array $a, array $b): int =>
                        ($a['distance'] <=> $b['distance'])
                        ?: strcmp($a['kind'], $b['kind'])
                        ?: ($a['id'] <=> $b['id'])
                );

                $nearest = array_slice($nearest, 0, $limit);
            });
        }

        return [
            'type' => 'FeatureCollection',
            'features' => array_column($nearest, 'feature'),

            'meta' => [
                'center' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ],
                'radius_km' => $radius,
                'kind' => $kind,
                'pet_type' => $filters['pet_type'] ?? null,
                'status' => $status,
                'limit' => $limit,
                'returned' => count($nearest),
                'total_within_radius' => $total,
                'truncated' => $total > count($nearest),
                'distance_method' => 'haversine',
            ],
        ];
    }

    private function boundCoordinates(
        Builder $query,
        float $latitude,
        float $longitude,
        float $radius
    ): void {
        $angular = $radius / self::EARTH_RADIUS_KM;
        $latitudeDelta = rad2deg($angular);

        $south = max(-90, $latitude - $latitudeDelta);
        $north = min(90, $latitude + $latitudeDelta);

        // Expand slightly to avoid floating-point rounding exclusions.
        $query->whereBetween('latitude', [
            $south - 0.0000001,
            $north + 0.0000001,
        ]);

        // A search circle touching a pole may cover every longitude.
        if ($south <= -90 || $north >= 90) {
            return;
        }

        $longitudeDelta = rad2deg(
            asin(
                min(
                    1.0,
                    sin($angular) / cos(deg2rad($latitude))
                )
            )
        ) + 0.0000001;

        $west = $longitude - $longitudeDelta;
        $east = $longitude + $longitudeDelta;

        // Group OR conditions so visibility restrictions still apply.
        $query->where(function (Builder $bounds) use ($west, $east) {
            if ($west < -180) {
                $bounds->where('longitude', '>=', $west + 360)
                    ->orWhere('longitude', '<=', $east);
            } elseif ($east > 180) {
                $bounds->where('longitude', '>=', $west)
                    ->orWhere('longitude', '<=', $east - 360);
            } else {
                $bounds->whereBetween('longitude', [$west, $east]);
            }
        });
    }

    private function distanceKm(
        float $latitude1,
        float $longitude1,
        float $latitude2,
        float $longitude2
    ): float {
        $lat1 = deg2rad($latitude1);
        $lat2 = deg2rad($latitude2);

        $a = sin(($lat2 - $lat1) / 2) ** 2
            + cos($lat1) * cos($lat2)
            * sin(deg2rad($longitude2 - $longitude1) / 2) ** 2;

        $a = max(0.0, min(1.0, $a));

        return 2 * self::EARTH_RADIUS_KM
            * atan2(sqrt($a), sqrt(1 - $a));
    }
}