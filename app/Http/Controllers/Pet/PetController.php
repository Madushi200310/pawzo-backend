<?php

namespace App\Http\Controllers\Pet;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pet\StorePetRequest;
use App\Http\Requests\Pet\UpdatePetRequest;
use App\Models\Pet;
use App\Models\PetPhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PetController extends Controller
{
    /**
     * Return the authenticated user's pets.
     */
    public function index(Request $request): JsonResponse
    {
        $pets = $request->user()
            ->pets()
            ->with('photos')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'message' => 'Pets fetched successfully.',
            'pets'    => $pets,
        ]);
    }

    /**
     * Create a new pet for the authenticated user.
     */
    public function store(StorePetRequest $request): JsonResponse
    {
        $pet = $request->user()->pets()->create($request->validated());

        return response()->json([
            'message' => 'Pet created successfully.',
            'pet'     => $pet->load('photos'),
        ], 201);
    }

    /**
     * Return a single pet (must belong to the authenticated user).
     */
    public function show(Request $request, Pet $pet): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);

        return response()->json([
            'message' => 'Pet fetched successfully.',
            'pet'     => $pet->load('photos'),
        ]);
    }

    /**
     * Update a pet.
     */
    public function update(UpdatePetRequest $request, Pet $pet): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);

        $pet->update($request->validated());

        return response()->json([
            'message' => 'Pet updated successfully.',
            'pet'     => $pet->fresh()->load('photos'),
        ]);
    }

    /**
     * Soft-delete a pet and remove its photo files from storage.
     */
    public function destroy(Request $request, Pet $pet): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);

        // Delete photo files from disk
        foreach ($pet->photos as $photo) {
            if (Storage::disk('public')->exists($photo->path)) {
                Storage::disk('public')->delete($photo->path);
            }
        }
        $pet->photos()->delete();

        // Soft-delete the pet
        $pet->delete();

        return response()->json([
            'message' => 'Pet deleted successfully.',
        ]);
    }

    /**
     * Upload one or more photos for a pet (max 5 total).
     */
    public function uploadPhotos(Request $request, Pet $pet): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);

        $request->validate([
            'photos'   => ['required', 'array', 'min:1', 'max:5'],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'], // 3 MB each
        ]);

        $existing = $pet->photos()->count();
        $incoming = count($request->file('photos'));

        if ($existing + $incoming > 5) {
            throw ValidationException::withMessages([
                'photos' => ["A pet can have at most 5 photos. You currently have {$existing}."],
            ]);
        }

        $created = [];

        foreach ($request->file('photos') as $index => $file) {
            $path = $file->store("pets/{$pet->id}", 'public');

            $created[] = $pet->photos()->create([
                'path'       => $path,
                'is_primary' => $pet->photos()->count() === 0 && $index === 0,
            ]);
        }

        return response()->json([
            'message' => 'Photos uploaded successfully.',
            'photos'  => $created,
            'pet'     => $pet->fresh()->load('photos'),
        ], 201);
    }

    /**
     * Delete a single photo belonging to a pet.
     */
    public function destroyPhoto(Request $request, Pet $pet, PetPhoto $photo): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);

        if ($photo->pet_id !== $pet->id) {
            throw ValidationException::withMessages([
                'photo' => ['This photo does not belong to this pet.'],
            ]);
        }

        if (Storage::disk('public')->exists($photo->path)) {
            Storage::disk('public')->delete($photo->path);
        }

        $wasPrimary = $photo->is_primary;
        $photo->delete();

        // If we removed the primary photo, promote the next one
        if ($wasPrimary) {
            $next = $pet->photos()->orderBy('id')->first();
            if ($next) {
                $next->update(['is_primary' => true]);
            }
        }

        return response()->json([
            'message' => 'Photo deleted successfully.',
            'pet'     => $pet->fresh()->load('photos'),
        ]);
    }

    /**
     * Ensure the pet belongs to the authenticated user.
     */
    private function authorizeOwnership(Request $request, Pet $pet): void
    {
        if ($pet->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this pet.');
        }
    }
}