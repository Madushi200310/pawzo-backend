<?php

namespace App\Services\Rentals;

use App\Models\PetRental;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PetRentalService
{
    public function create(User $user, array $data, array $photos): PetRental
    {
        $saved = [];

        try {
            return DB::transaction(function () use ($user, $data, $photos, &$saved) {
                unset($data['photos']);
                if (isset($data['available_from'])) {
                    $data['available_from'] = Carbon::parse($data['available_from'])->toDateString();
                }
                if (isset($data['available_to'])) {
                    $data['available_to'] = Carbon::parse($data['available_to'])->toDateString();
                }

                $petRental = new PetRental($data);
                $petRental->user()->associate($user);
                $petRental->status = 'active';
                $petRental->save();
                $this->storePhotos($petRental, $photos, $saved);

                return $petRental->load('photos');
            });
        } catch (Throwable $error) {
            $this->cleanupFiles($saved);
            throw $error;
        }
    }

    public function update(PetRental $petRental, array $data): PetRental
    {
        if (isset($data['available_from'])) {
            $data['available_from'] = Carbon::parse($data['available_from'])->toDateString();
        }

        if (isset($data['available_to'])) {
            $data['available_to'] = Carbon::parse($data['available_to'])->toDateString();
        }

        return DB::transaction(function () use ($petRental, $data) {
            $locked = PetRental::query()->lockForUpdate()->findOrFail($petRental->id);
            $locked->fill($data)->save();

            return $locked->load('photos');
        });
    }

    public function addPhotos(PetRental $petRental, array $photos): PetRental
    {
        $saved = [];

        try {
            return DB::transaction(function () use ($petRental, $photos, &$saved) {
                $locked = PetRental::query()->lockForUpdate()->findOrFail($petRental->id);
                if ($locked->photos()->count() + count($photos) > PetRental::MAX_PHOTOS) {
                    throw ValidationException::withMessages([
                        'photos' => ['A listing can have at most 5 photos.'],
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

    public function deletePhoto(PetRental $petRental, int $photoId): void
    {
        $path = DB::transaction(function () use ($petRental, $photoId) {
            $locked = PetRental::query()->lockForUpdate()->findOrFail($petRental->id);
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

    public function delete(PetRental $petRental): void
    {
        $paths = DB::transaction(function () use ($petRental) {
            $locked = PetRental::query()->lockForUpdate()->findOrFail($petRental->id);
            $paths = $locked->photos()->pluck('path')->all();
            $locked->delete();

            return $paths;
        });

        $this->cleanupFiles($paths);
    }

    private function storePhotos(PetRental $petRental, array $photos, array &$saved): void
    {
        foreach ($photos as $photo) {
            $path = $photo->store('pet-rentals/'.$petRental->id, 'public');
            if (! is_string($path) || $path === '') {
                throw new RuntimeException('Unable to store a pet-rental photo.');
            }

            $saved[] = $path;
            $petRental->photos()->create(['path' => $path]);
        }
    }

    private function cleanupFiles(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                if (! Storage::disk('public')->delete($path)) {
                    Log::warning('Pet-rental photo cleanup failed.', ['path' => $path]);
                }
            } catch (Throwable $error) {
                Log::warning('Pet-rental photo cleanup failed.', [
                    'path' => $path,
                    'error' => $error->getMessage(),
                ]);
            }
        }
    }
}
