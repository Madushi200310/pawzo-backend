<?php

namespace App\Services\LostPets;

use App\Models\LostPetReport;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class LostPetService
{
    public function create(User $user, array $data, array $photos): LostPetReport
    {
        $saved = [];

        try {
            return DB::transaction(function () use ($user, $data, $photos, &$saved) {
                unset($data['photos']);
                $data['lost_at'] = Carbon::parse($data['lost_at'])->utc();
                $report = new LostPetReport($data);
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

    public function update(LostPetReport $report, array $data): LostPetReport
    {
        if (isset($data['lost_at'])) {
            $data['lost_at'] = Carbon::parse($data['lost_at'])->utc();
        }

        return DB::transaction(function () use ($report, $data) {
            $locked = LostPetReport::query()->lockForUpdate()->findOrFail($report->id);
            $locked->fill($data)->save();

            return $locked->load('photos');
        });
    }

    public function changeStatus(LostPetReport $report, string $status): LostPetReport
    {
        return DB::transaction(function () use ($report, $status) {
            $locked = LostPetReport::query()->lockForUpdate()->findOrFail($report->id);
            if ($locked->status !== $status) {
                $locked->status = $status;
                $locked->resolved_at = $status === 'open' ? null : now();
                $locked->save();
            }

            return $locked->load('photos');
        });
    }

    public function addPhotos(LostPetReport $report, array $photos): LostPetReport
    {
        $saved = [];

        try {
            return DB::transaction(function () use ($report, $photos, &$saved) {
                // Serialize uploads so concurrent requests cannot exceed the limit.
                $locked = LostPetReport::query()->lockForUpdate()->findOrFail($report->id);
                if ($locked->photos()->count() + count($photos) > LostPetReport::MAX_PHOTOS) {
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

    public function deletePhoto(LostPetReport $report, int $photoId): void
    {
        $path = DB::transaction(function () use ($report, $photoId) {
            $locked = LostPetReport::query()->lockForUpdate()->findOrFail($report->id);
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

    public function delete(LostPetReport $report): void
    {
        $paths = DB::transaction(function () use ($report) {
            $locked = LostPetReport::query()->lockForUpdate()->findOrFail($report->id);
            $paths = $locked->photos()->pluck('path')->all();
            // Photo rows cascade; remove disk files only after the DB commit succeeds.
            $locked->delete();

            return $paths;
        });

        $this->cleanupFiles($paths);
    }

    private function storePhotos(LostPetReport $report, array $photos, array &$saved): void
    {
        foreach ($photos as $photo) {
            $path = $photo->store('lost-pets/'.$report->id, 'public');
            if (! is_string($path) || $path === '') {
                throw new RuntimeException('Unable to store a lost-pet photo.');
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
                    Log::warning('Lost-pet photo cleanup failed.', ['path' => $path]);
                }
            } catch (Throwable $error) {
                // Keep the original error / successful DB result and record cleanup work.
                Log::warning('Lost-pet photo cleanup failed.', [
                    'path' => $path, 'error' => $error->getMessage(),
                ]);
            }
        }
    }
}