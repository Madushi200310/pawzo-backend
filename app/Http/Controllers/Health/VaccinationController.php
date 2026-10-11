<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\StoreVaccinationRequest;
use App\Http\Requests\Health\UpdateVaccinationRequest;
use App\Models\Pet;
use App\Models\Vaccination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VaccinationController extends Controller
{
    /**
     * List all vaccinations for a pet (newest first).
     */
    public function index(Request $request, Pet $pet): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);

        $vaccinations = $pet->vaccinations()
            ->orderByDesc('given_date')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'message'      => 'Vaccinations fetched successfully.',
            'vaccinations' => $vaccinations,
        ]);
    }

    /**
     * Create a new vaccination record.
     */
    public function store(StoreVaccinationRequest $request, Pet $pet): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);

        $vaccination = $pet->vaccinations()->create($request->validated());

        return response()->json([
            'message'     => 'Vaccination recorded successfully.',
            'vaccination' => $vaccination,
        ], 201);
    }

    /**
     * Show a single vaccination.
     */
    public function show(Request $request, Pet $pet, Vaccination $vaccination): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);
        $this->ensureBelongsToPet($vaccination, $pet);

        return response()->json([
            'message'     => 'Vaccination fetched successfully.',
            'vaccination' => $vaccination,
        ]);
    }

    /**
     * Update a vaccination.
     */
    public function update(UpdateVaccinationRequest $request, Pet $pet, Vaccination $vaccination): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);
        $this->ensureBelongsToPet($vaccination, $pet);

        $vaccination->update($request->validated());

        return response()->json([
            'message'     => 'Vaccination updated successfully.',
            'vaccination' => $vaccination->fresh(),
        ]);
    }

    /**
     * Soft-delete a vaccination.
     */
    public function destroy(Request $request, Pet $pet, Vaccination $vaccination): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);
        $this->ensureBelongsToPet($vaccination, $pet);

        $vaccination->delete();

        return response()->json([
            'message' => 'Vaccination deleted successfully.',
        ]);
    }

    /**
     * Upcoming & overdue vaccinations for a pet.
     * Sorted by next_due_date ascending.
     */
    public function upcoming(Request $request, Pet $pet): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);

        $items = $pet->vaccinations()
            ->whereNotNull('next_due_date')
            ->orderBy('next_due_date')
            ->get()
            ->map(function (Vaccination $v) {
                return [
                    'id'            => $v->id,
                    'vaccine_name'  => $v->vaccine_name,
                    'given_date'    => $v->given_date->toDateString(),
                    'next_due_date' => $v->next_due_date->toDateString(),
                    'days_until'    => now()->diffInDays($v->next_due_date, false),
                    'is_overdue'    => $v->isOverdue(),
                ];
            });

        return response()->json([
            'message' => 'Upcoming vaccinations fetched successfully.',
            'items'   => $items,
            'overdue' => $items->where('is_overdue', true)->count(),
        ]);
    }

    // ---------------- Helpers ----------------

    private function authorizeOwnership(Request $request, Pet $pet): void
    {
        if ($pet->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this pet.');
        }
    }

    private function ensureBelongsToPet(Vaccination $vaccination, Pet $pet): void
    {
        if ($vaccination->pet_id !== $pet->id) {
            throw ValidationException::withMessages([
                'vaccination' => ['This vaccination does not belong to this pet.'],
            ]);
        }
    }
}