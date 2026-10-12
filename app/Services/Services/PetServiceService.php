<?php

namespace App\Services\Services;

use App\Models\Business;
use App\Models\PetService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PetServiceService
{
    /**
     * Ensure the given user has an approved business.
     * Returns the first approved business or throws 403.
     */
    public function requireApprovedBusiness(User $user): Business
    {
        $business = $user->businesses()
            ->where('status', 'approved')
            ->first();

        if (! $business) {
            abort(403, 'You need an approved business to publish service listings.');
        }

        return $business;
    }

    public function create(User $user, array $data, array $photos): PetService
    {
        $business = $this->requireApprovedBusiness($user);
        $saved = [];

        try {
            return DB::transaction(function () use ($user, $business, $data, $photos, &$saved) {
                unset($data['photos']);

                $service = new PetService($data);
                $service->user()->associate($user);
                $service->business()->associate($business);
                $service->slug = PetService::generateUniqueSlug($data['name']);
                $service->status = 'pending';
                $service->save();

                $this->storePhotos($service, $photos, $saved);

                return $service->load('photos');
            });
        } catch (Throwable $error) {
            $this->cleanupFiles($saved);
            throw $error;
        }
    }

    public function update(PetService $service, array $data): PetService
    {
        return DB::transaction(function () use ($service, $data) {
            $locked = PetService::query()->lockForUpdate()->findOrFail($service->id);

            // If name changes, regenerate slug
            if (isset($data['name']) && $data['name'] !== $locked->name) {
                $data['slug'] = PetService::generateUniqueSlug($data['name'], $locked->id);
            }

            $locked->fill($data)->save();

            return $locked->load('photos');
        });
    }

    public function addPhotos(PetService $service, array $photos): PetService
    {
        $saved = [];

        try {
            return DB::transaction(function () use ($service, $photos, &$saved) {
                $locked = PetService::query()->lockForUpdate()->findOrFail($service->id);
                if ($locked->photos()->count() + count($photos) > PetService::MAX_PHOTOS) {
                    throw ValidationException::withMessages([
                        'photos' => ['A service can have at most 5 photos.'],
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

    public function deletePhoto(PetService $service, int $photoId): void
    {
        $path = DB::transaction(function () use ($service, $photoId) {
            $locked = PetService::query()->lockForUpdate()->findOrFail($service->id);
            $photo = $locked->photos()->findOrFail($photoId);
            if ($locked->photos()->count() <= 1) {
                throw ValidationException::withMessages([
                    'photos' => ['Keep at least one photo.'],
                ]);
            }
            $path = $photo->path;
            $photo->delete();
            $locked->touch();

            return $path;
        });

        $this->cleanupFiles([$path]);
    }

    public function delete(PetService $service): void
    {
        $paths = DB::transaction(function () use ($service) {
            $locked = PetService::query()->lockForUpdate()->findOrFail($service->id);
            $paths = $locked->photos()->pluck('path')->all();
            $locked->delete();

            return $paths;
        });

        $this->cleanupFiles($paths);
    }

    private function storePhotos(PetService $service, array $photos, array &$saved): void
    {
        foreach ($photos as $photo) {
            $path = $photo->store('pet-services/'.$service->id, 'public');
            if (! is_string($path) || $path === '') {
                throw new RuntimeException('Unable to store a service photo.');
            }
            $saved[] = $path;
            $service->photos()->create(['path' => $path]);
        }
    }

    private function cleanupFiles(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                if (! Storage::disk('public')->delete($path)) {
                    Log::warning('Service photo cleanup failed.', ['path' => $path]);
                }
            } catch (Throwable $error) {
                Log::warning('Service photo cleanup failed.', [
                    'path' => $path, 'error' => $error->getMessage(),
                ]);
            }
        }
    }
}