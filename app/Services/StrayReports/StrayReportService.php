<?php

namespace App\Services\StrayReports;

use App\Models\StrayReport;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class StrayReportService
{
    public function create(User $user, array $data, array $photos): StrayReport
    {
        $saved = [];

        try {
            return DB::transaction(function () use ($user, $data, $photos, &$saved) {
                unset($data['photos']);
                $data['spotted_at'] = Carbon::parse($data['spotted_at'])->utc();
                $report = new StrayReport($data);
                $report->user()->associate($user);
                $report->status = 'open';
                $report->save();
                $this->storePhotos($report, $photos, $saved);

                return $report->load('photos');
            });
        } catch (Throwable $error) {
            // A DB rollback cannot undo filesystem writes.
            $this->cleanupFiles($saved);
            throw $error;
        }
    }

    public function update(StrayReport $report, array $data): StrayReport
    {
        if (isset($data['spotted_at'])) {
            $data['spotted_at'] = Carbon::parse($data['spotted_at'])->utc();
        }

        return DB::transaction(function () use ($report, $data) {
            $locked = StrayReport::query()->lockForUpdate()->findOrFail($report->id);
            $locked->fill($data)->save();

            return $locked->load('photos');
        });
    }

    public function changeStatus(StrayReport $report, string $status): StrayReport
    {
        return DB::transaction(function () use ($report, $status) {
            $locked = StrayReport::query()->lockForUpdate()->findOrFail($report->id);
            if ($locked->status !== $status) {
                $locked->status = $status;
                $locked->resolved_at = $status === 'open' ? null : now();
                $locked->save();
            }

            return $locked->load('photos');
        });
    }

    public function addPhotos(StrayReport $report, array $photos): StrayReport
    {
        $saved = [];

        try {
            return DB::transaction(function () use ($report, $photos, &$saved) {
                // Serialize uploads so concurrent requests cannot exceed the limit.
                $locked = StrayReport::query()->lockForUpdate()->findOrFail($report->id);
                if ($locked->photos()->count() + count($photos) > StrayReport::MAX_PHOTOS) {
                    throw ValidationException::withMessages([
                        'photos' => ['A report can have at most 5 photos.'],
                    ]);
                }
                $this->storePhotos($locked, $photos, $saved);
                $locked->touch();

                return $locked->load('photos');
            });
        } catch (Throwable $error) {
            $this->cleanupFiles($saved);
            throw $error;
        }
    }

    public function deletePhoto(StrayReport $report, int $photoId): void
    {
        $path = DB::transaction(function () use ($report, $photoId) {
            $locked = StrayReport::query()->lockForUpdate()->findOrFail($report->id);
            // Look up the photo through this report, never through a global photo ID.
            $photo = $locked->photos()->findOrFail($photoId);
            if ($locked->photos()->count() <= 1) {
                throw ValidationException::withMessages([
                    'photos' => ['Keep at least one photo. Add a replacement before deleting the last photo.'],
                ]);
            }
            $path = $photo->path;
            $photo->delete();
            $locked->touch();

            return $path;
        });

        $this->cleanupFiles([$path]);
    }

    public function delete(StrayReport $report): void
    {
        $paths = DB::transaction(function () use ($report) {
            $locked = StrayReport::query()->lockForUpdate()->findOrFail($report->id);
            $paths = $locked->photos()->pluck('path')->all();
            // Photo rows cascade; remove disk files only after the DB commit succeeds.
            $locked->delete();

            return $paths;
        });

        $this->cleanupFiles($paths);
    }

    private function storePhotos(StrayReport $report, array $photos, array &$saved): void
    {
        foreach ($photos as $photo) {
            $path = $photo->store('stray-reports/'.$report->id, 'public');
            if (! is_string($path) || $path === '') {
                throw new RuntimeException('Unable to store a stray-report photo.');
            }
            $saved[] = $path;
            $report->photos()->create(['path' => $path]);
        }
    }

    private function cleanupFiles(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                if (! Storage::disk('public')->delete($path)) {
                    Log::warning('Stray-report photo cleanup failed.', ['path' => $path]);
                }
            } catch (Throwable $error) {
                // Keep the original error / successful DB result and record cleanup work.
                Log::warning('Stray-report photo cleanup failed.', [
                    'path' => $path, 'error' => $error->getMessage(),
                ]);
            }
        }
    }
}