<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Models\Pet;
use App\Models\Reminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReminderController extends Controller
{
    /**
     * List reminders for the authenticated user (optionally filter by pet, type, status).
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->reminders()->with('pet:id,name,type');

        if ($request->filled('pet_id')) {
            $query->where('pet_id', $request->integer('pet_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('status')) {
            match ($request->string('status')->toString()) {
                'pending'   => $query->where('is_completed', false)->where('is_dismissed', false),
                'completed' => $query->where('is_completed', true),
                'dismissed' => $query->where('is_dismissed', true),
                'overdue'   => $query->where('is_completed', false)
                                       ->where('is_dismissed', false)
                                       ->where('due_date', '<', now()->startOfDay()),
                default     => null,
            };
        }

        $reminders = $query->orderBy('due_date')->orderBy('id')->get();

        return response()->json([
            'message'   => 'Reminders fetched successfully.',
            'reminders' => $reminders,
        ]);
    }

    /**
     * List reminders for a specific pet (must own it).
     */
    public function forPet(Request $request, Pet $pet): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);

        $reminders = $pet->reminders()
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        return response()->json([
            'message'   => 'Reminders fetched successfully.',
            'reminders' => $reminders,
        ]);
    }

    /**
     * Create a manual reminder (deworming, vet appointment, grooming, or custom).
     */
    public function store(Request $request, Pet $pet): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);

        $data = $request->validate([
            'type'        => ['required', Rule::in(Reminder::TYPES)],
            'title'       => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'due_date'    => ['required', 'date'],
            'remind_at'   => ['nullable', 'date'],
        ]);

        $reminder = $pet->reminders()->create([
            ...$data,
            'user_id' => $request->user()->id,
        ]);

        return response()->json([
            'message'  => 'Reminder created successfully.',
            'reminder' => $reminder,
        ], 201);
    }

    /**
     * Show a single reminder (must belong to the user).
     */
    public function show(Request $request, Reminder $reminder): JsonResponse
    {
        $this->authorizeReminder($request, $reminder);

        return response()->json([
            'message'  => 'Reminder fetched successfully.',
            'reminder' => $reminder->load('pet:id,name,type'),
        ]);
    }

    /**
     * Update a manual reminder. Auto-generated ones can still be updated (title/due_date) but type is locked.
     */
    public function update(Request $request, Reminder $reminder): JsonResponse
    {
        $this->authorizeReminder($request, $reminder);

        $data = $request->validate([
            'title'       => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'due_date'    => ['sometimes', 'required', 'date'],
            'remind_at'   => ['sometimes', 'nullable', 'date'],
        ]);

        $reminder->update($data);

        return response()->json([
            'message'  => 'Reminder updated successfully.',
            'reminder' => $reminder->fresh(),
        ]);
    }

    /**
     * Mark a reminder as completed.
     */
    public function complete(Request $request, Reminder $reminder): JsonResponse
    {
        $this->authorizeReminder($request, $reminder);

        $reminder->update([
            'is_completed' => true,
            'completed_at' => now(),
            'is_dismissed' => false,
        ]);

        return response()->json([
            'message'  => 'Reminder marked as completed.',
            'reminder' => $reminder->fresh(),
        ]);
    }

    /**
     * Dismiss a reminder (hide without completing).
     */
    public function dismiss(Request $request, Reminder $reminder): JsonResponse
    {
        $this->authorizeReminder($request, $reminder);

        $reminder->update(['is_dismissed' => true]);

        return response()->json([
            'message'  => 'Reminder dismissed.',
            'reminder' => $reminder->fresh(),
        ]);
    }

    /**
     * Delete a reminder (hard delete — user-owned data).
     */
    public function destroy(Request $request, Reminder $reminder): JsonResponse
    {
        $this->authorizeReminder($request, $reminder);

        $reminder->delete();

        return response()->json([
            'message' => 'Reminder deleted successfully.',
        ]);
    }

    // ---------------- Helpers ----------------

    private function authorizeOwnership(Request $request, Pet $pet): void
    {
        if ($pet->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this pet.');
        }
    }

    private function authorizeReminder(Request $request, Reminder $reminder): void
    {
        if ($reminder->user_id !== $request->user()->id) {
            throw ValidationException::withMessages([
                'reminder' => ['This reminder does not belong to you.'],
            ]);
        }
    }
}