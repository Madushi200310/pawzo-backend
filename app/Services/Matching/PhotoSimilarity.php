<?php

namespace App\Services\Matching;

use Illuminate\Support\Facades\Storage;
use Throwable;

class PhotoSimilarity
{
    // Cache exists only during this service instance's lifetime.
    private array $cache = [];

    public function compare(iterable $left, iterable $right): ?float
    {
        $best = null;

        foreach ($left as $a) {
            $one = $this->fingerprint($a->path);

            if ($one === null) {
                continue;
            }

            foreach ($right as $b) {
                $two = $this->fingerprint($b->path);

                if ($two === null) {
                    continue;
                }

                $different = 0;

                for ($i = 0; $i < 64; $i++) {
                    $different += (int) (
                        $one['bits'][$i] !== $two['bits'][$i]
                    );
                }

                $colorDifference = 0;

                for ($i = 0; $i < 3; $i++) {
                    $colorDifference += abs(
                        $one['rgb'][$i] - $two['rgb'][$i]
                    );
                }

                $similarity = $colorDifference / 3 > 60
                    ? 0
                    : 1 - $different / 64;

                $best = max($best ?? 0, $similarity);
            }
        }

        return $best === null ? null : round($best, 4);
    }

    private function fingerprint(string $path): ?array
    {
        if (array_key_exists($path, $this->cache)) {
            return $this->cache[$path];
        }

        $this->cache[$path] = null;

        if (! extension_loaded('gd')) {
            return null;
        }

        $source = $small = null;

        try {
            $disk = Storage::disk('public');

            if (
                ! $disk->exists($path)
                || $disk->size($path) > 5 * 1024 * 1024
            ) {
                return null;
            }

            $bytes = $disk->get($path);
            $size = @getimagesizefromstring($bytes);

            // Limit decoded memory and reject tiny/unsupported images.
            if (
                ! $size
                || min($size[0], $size[1]) < 64
                || $size[0] * $size[1] > 6000000
                || ! in_array(
                    $size[2],
                    [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP],
                    true
                )
            ) {
                return null;
            }

            $source = @imagecreatefromstring($bytes);

            if ($source === false) {
                return null;
            }

            $small = imagecreatetruecolor(9, 8);

            imagefill(
                $small,
                0,
                0,
                imagecolorallocate($small, 255, 255, 255)
            );

            imagecopyresampled(
                $small,
                $source,
                0,
                0,
                0,
                0,
                9,
                8,
                $size[0],
                $size[1]
            );

            $gray = [];
            $totals = [0, 0, 0];

            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 9; $x++) {
                    $pixel = imagecolorat($small, $x, $y);

                    $rgb = [
                        ($pixel >> 16) & 255,
                        ($pixel >> 8) & 255,
                        $pixel & 255,
                    ];

                    $gray[] =
                        0.299 * $rgb[0]
                        + 0.587 * $rgb[1]
                        + 0.114 * $rgb[2];

                    foreach ($rgb as $i => $value) {
                        $totals[$i] += $value;
                    }
                }
            }

            $mean = array_sum($gray) / 72;

            $variance = array_sum(
                array_map(
                    fn ($value) => ($value - $mean) ** 2,
                    $gray
                )
            ) / 72;

            // Flat-color images can produce misleading identical hashes.
            if ($variance < 100) {
                return null;
            }

            $bits = '';

            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 8; $x++) {
                    $bits .=
                        $gray[$y * 9 + $x] > $gray[$y * 9 + $x + 1]
                            ? '1'
                            : '0';
                }
            }

            $ones = substr_count($bits, '1');

            if ($ones < 4 || $ones > 60) {
                return null;
            }

            return $this->cache[$path] = [
                'bits' => $bits,
                'rgb' => array_map(
                    fn ($value) => $value / 72,
                    $totals
                ),
            ];
        } catch (Throwable) {
            // Unusable photos must not break metadata matching.
            return null;
        } finally {
            unset($source, $small);
        }
    }
}