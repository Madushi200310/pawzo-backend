<?php

namespace App\Services\Matching;

use App\Models\FoundPetReport;
use App\Models\LostPetReport;
use App\Models\PetMatch;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class SmartMatchingService
{
    public function refresh(string $type, int $id): int
    {
        if (! in_array($type, ['lost', 'found'], true)) {
            throw new InvalidArgumentException('Unknown report type.');
        }

        $isLost = $type === 'lost';

        $sourceClass = $isLost
            ? LostPetReport::class
            : FoundPetReport::class;

        $candidateClass = $isLost
            ? FoundPetReport::class
            : LostPetReport::class;

        $foreignKey = $isLost
            ? 'lost_pet_report_id'
            : 'found_pet_report_id';

        return DB::transaction(function () use (
            $isLost,
            $sourceClass,
            $candidateClass,
            $foreignKey,
            $id
        ) {
            $lock = DB::table('pet_matching_locks')
                ->where('id', 1)
                ->lockForUpdate()
                ->first();

            if (! $lock) {
                throw new RuntimeException(
                    'The matching migration has not been initialized.'
                );
            }

            $source = $sourceClass::with(['user', 'photos'])->find($id);
            $existing = PetMatch::where($foreignKey, $id);

            if (
                ! $source
                || $source->status !== 'open'
                || ! $source->user?->is_active
                || ! $source->user?->email_verified_at
            ) {
                $existing->delete();

                return 0;
            }

            $days = (int) config('smart_matching.maximum_days', 90);

            $date = $isLost
                ? $source->lost_at
                : $source->found_at;

            $dateRange = $isLost
                ? [$date, $date->copy()->addDays($days)]
                : [$date->copy()->subDays($days), $date];

            $candidates = $candidateClass::query()
                ->where('status', 'open')
                ->where('pet_type', $source->pet_type)
                ->whereHas('user', function ($query) {
                    $query->where('is_active', true)
                        ->whereNotNull('email_verified_at');
                })
                ->whereBetween(
                    $isLost ? 'found_at' : 'lost_at',
                    $dateRange
                )
                ->with('photos');

            $kept = [];
            $photos = new PhotoSimilarity();

            $candidates->chunkById(
                100,
                function ($batch) use (
                    $source,
                    $isLost,
                    $photos,
                    &$kept
                ) {
                    foreach ($batch as $candidate) {
                        $lost = $isLost ? $source : $candidate;
                        $found = $isLost ? $candidate : $source;

                        $result = $this->score($lost, $found, $photos);

                        if (
                            $result === null
                            || $result['score'] < config(
                                'smart_matching.minimum_score',
                                55
                            )
                        ) {
                            continue;
                        }

                        $match = PetMatch::updateOrCreate(
                            [
                                'lost_pet_report_id' => $lost->id,
                                'found_pet_report_id' => $found->id,
                            ],
                            [
                                'score' => $result['score'],
                                'details' => $result['details'],
                                'lost_updated_at' =>
                                    $lost->getRawOriginal('updated_at'),
                                'found_updated_at' =>
                                    $found->getRawOriginal('updated_at'),
                            ]
                        );

                        $kept[] = $match->id;
                    }
                }
            );

            // Remove suggestions that no longer qualify.
            if ($kept !== []) {
                $existing->whereNotIn('id', $kept);
            }

            $existing->delete();

            return count($kept);
        }, 3);
    }

    public function score(
        LostPetReport $lost,
        FoundPetReport $found,
        PhotoSimilarity $photos
    ): ?array {
        $days = (
            $found->found_at->getTimestamp()
            - $lost->lost_at->getTimestamp()
        ) / 86400;

        $km = $this->distance($lost, $found);

        $knownGender =
            $lost->gender !== 'unknown'
            && $found->gender !== 'unknown';

        if (
            $lost->pet_type !== $found->pet_type
            || $days < 0
            || $days > config('smart_matching.maximum_days', 90)
            || $km > config('smart_matching.maximum_distance_km', 50)
            || ($knownGender && $lost->gender !== $found->gender)
        ) {
            return null;
        }

        $photo = $photos->compare($lost->photos, $found->photos);

        $parts = [
            'breed' => [
                20 * $this->similarity($lost->breed, $found->breed),
                20,
                'Breed word overlap',
            ],

            'color' => [
                15 * $this->similarity($lost->color, $found->color),
                15,
                'Color word overlap',
            ],

            'gender' => [
                $knownGender ? 10 : 0,
                10,
                $knownGender
                    ? 'Same reported gender'
                    : 'Gender unknown',
            ],

            'location' => [
                match (true) {
                    $km <= 2 => 25,
                    $km <= 5 => 22,
                    $km <= 10 => 18,
                    $km <= 25 => 12,
                    default => 5,
                },
                25,
                'Distance between reported locations',
            ],

            'date' => [
                match (true) {
                    $days <= 3 => 15,
                    $days <= 7 => 12,
                    $days <= 30 => 8,
                    default => 3,
                },
                15,
                'Elapsed time from loss to finding',
            ],

            'characteristics' => [
                10 * $this->similarity(
                    $lost->distinguishing_features.' '.$lost->description,
                    $found->distinguishing_features.' '.$found->description
                ),
                10,
                'Description and distinguishing-feature word overlap',
            ],

            'photos' => [
                $photo !== null && $photo >= 0.90 ? 5 * $photo : 0,
                5,
                $photo === null
                    ? 'No usable image comparison'
                    : 'Visual fingerprint similarity; not animal identification',
            ],
        ];

        $breakdown = [];

        foreach ($parts as $name => [$points, $maximum, $reason]) {
            $breakdown[$name] = [
                'points' => round($points, 2),
                'maximum' => $maximum,
                'reason' => $reason,
            ];
        }

        return [
            'score' => round(
                array_sum(array_column($breakdown, 'points')),
                2
            ),

            'details' => [
                'algorithm' => 'rules-v1',
                'distance_km' => round($km, 3),
                'days_between' => round($days, 3),
                'breakdown' => $breakdown,
                'photo_method' => 'dhash-with-mean-color-v1',
                'photo_similarity' => $photo,
            ],
        ];
    }

    private function similarity(?string $a, ?string $b): float
    {
        $tokens = function (?string $value): array {
            $value = str_replace(
                'grey',
                'gray',
                mb_strtolower(trim($value ?? ''))
            );

            $words = preg_split(
                '/[^\p{L}\p{N}]+/u',
                $value,
                -1,
                PREG_SPLIT_NO_EMPTY
            ) ?: [];

            $stop = [
                'a', 'an', 'the', 'and', 'or', 'with',
                'on', 'in', 'at', 'near', 'is', 'was',
                'has', 'of', 'this', 'that', 'pet',
                'dog', 'cat', 'lost', 'found',
                'unknown', 'not', 'known', 'n', 'na',
            ];

            return array_values(
                array_diff(array_unique($words), $stop)
            );
        };

        $left = $tokens($a);
        $right = $tokens($b);

        if ($left === [] || $right === []) {
            return 0;
        }

        return 2 * count(array_intersect($left, $right))
            / (count($left) + count($right));
    }

    private function distance(
        LostPetReport $a,
        FoundPetReport $b
    ): float {
        $lat1 = deg2rad((float) $a->latitude);
        $lat2 = deg2rad((float) $b->latitude);

        $dLat = $lat2 - $lat1;

        $dLon = deg2rad(
            (float) $b->longitude - (float) $a->longitude
        );

        $h = sin($dLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;

        return 6371.0088 * 2 * asin(
            sqrt(max(0, min(1, $h)))
        );
    }
}