<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewPetReportRequest;
use App\Models\Pet;
use App\Models\PetReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPetController extends Controller
{
    /**
     * List pets (searchable, filterable, with soft-deleted support).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Pet::query()->with(['user:id,name,email', 'photos']);

        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        if ($request->filled('search')) {
            $search = strtolower(trim($request->string('search')->toString()));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(pets.name) LIKE ?', ["%{$search}%"])
                  ->orWhereHas('user', function ($u) use ($search) {
                      $u->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                        ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
                  });
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        $status = $request->string('status')->toString() ?: 'all';
        match ($status) {
            'active'  => $query->whereNull('deleted_at'),
            'trashed' => $query->onlyTrashed(),
            default   => null,
        };

        $perPage = min((int) $request->integer('per_page', 20), 100);

        $pets = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'message' => 'Pets fetched successfully.',
            'pets'    => $pets,
        ]);
    }

    /**
     * Show a single pet with owner info + summary.
     */
    public function show(Request $request, Pet $pet): JsonResponse
    {
        $pet = Pet::withTrashed()
            ->with(['user:id,name,email,phone', 'photos'])
            ->findOrFail($pet->id);

        return response()->json([
            'message' => 'Pet fetched successfully.',
            'pet'     => $pet,
            'summary' => [
                'photos'          => $pet->photos()->count(),
                'health_records'  => $pet->healthRecords()->count(),
                'vaccinations'    => $pet->vaccinations()->count(),
                'reminders'       => $pet->reminders()->count(),
                'reports'         => $pet->reports()->count(),
                'pending_reports' => $pet->reports()->where('status', 'pending')->count(),
            ],
        ]);
    }

    /**
     * Soft-delete a pet.
     */
    public function destroy(Request $request, Pet $pet): JsonResponse
    {
        $pet->delete();

        return response()->json([
            'message' => 'Pet removed successfully.',
        ]);
    }

    /**
     * Restore a soft-deleted pet.
     * Uses explicit {id} (not route model binding) because Laravel's default
     * binding excludes trashed models.
     */
    public function restore(Request $request, int $id): JsonResponse
    {
        $pet = Pet::onlyTrashed()->find($id);

        if (! $pet) {
            return response()->json([
                'message' => 'No trashed pet found with that ID.',
            ], 404);
        }

        $pet->restore();

        return response()->json([
            'message' => 'Pet restored successfully.',
            'pet'     => $pet->fresh(),
        ]);
    }

    // ---------------- Pet Reports ----------------

    /**
     * List reported pets.
     */
    public function reports(Request $request): JsonResponse
    {
        $query = PetReport::with([
            'pet:id,name,type,user_id,deleted_at',
            'pet.user:id,name,email',
            'reporter:id,name,email',
            'reviewer:id,name',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('reason')) {
            $query->where('reason', $request->string('reason'));
        }

        $perPage = min((int) $request->integer('per_page', 20), 100);

        $reports = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'message' => 'Reports fetched successfully.',
            'reports' => $reports,
        ]);
    }

    /**
     * Review a report.
     */
    public function reviewReport(ReviewPetReportRequest $request, PetReport $report): JsonResponse
    {
        $report->update([
            'status'       => $request->input('status'),
            'review_notes' => $request->input('review_notes'),
            'reviewed_by'  => $request->user()->id,
            'reviewed_at'  => now(),
        ]);

        return response()->json([
            'message' => 'Report reviewed successfully.',
            'report'  => $report->fresh()->load(['pet:id,name', 'reporter:id,name']),
        ]);
    }
}