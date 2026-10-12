<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    /**
     * List users with search, status filter, and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        // Include soft-deleted users only when explicitly requested
        if ($request->boolean('with_trashed')) {
            $query->withTrashed();
        }

        // Search by name or email (works on both PostgreSQL and SQLite)
        if ($request->filled('search')) {
            $search = strtolower(trim($request->string('search')->toString()));
            $query->where(function ($q) use ($search) {
                $q->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                  ->orWhereRaw('LOWER(email) LIKE ?', ["%{$search}%"]);
            });
        }

        // Filter by status
        $status = $request->string('status')->toString() ?: 'all';
        match ($status) {
            'active'   => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            default    => null, // all
        };

        $perPage = min((int) $request->integer('per_page', 20), 100);

        $users = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'message' => 'Users fetched successfully.',
            'users'   => $users,
        ]);
    }

    /**
     * Show a single user with activity summary.
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $user = User::withTrashed()->findOrFail($user->id);

        return response()->json([
            'message' => 'User fetched successfully.',
            'user'    => $user,
            'summary' => [
                'pets'           => $user->petsCount(),
                'health_records' => $user->healthRecordsCount(),
                'vaccinations'   => $user->vaccinationsCount(),
                'reminders'      => $user->reminders()->count(),
            ],
        ]);
    }

    /**
     * Activate or deactivate a user.
     */
    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $admin    = $request->user();
        $isActive = $request->boolean('is_active');

        if ($user->id === $admin->id) {
            throw ValidationException::withMessages([
                'user' => ['You cannot change your own active status.'],
            ]);
        }

        if ($user->isAdmin() && ! $isActive) {
            throw ValidationException::withMessages([
                'user' => ['You cannot deactivate another admin.'],
            ]);
        }

        $user->update(['is_active' => $isActive]);

        return response()->json([
            'message' => $isActive ? 'User activated successfully.' : 'User deactivated successfully.',
            'user'    => $user->fresh(),
        ]);
    }

    /**
     * Soft-delete a user.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $admin = $request->user();

        if ($user->id === $admin->id) {
            throw ValidationException::withMessages([
                'user' => ['You cannot delete your own account.'],
            ]);
        }

        if ($user->isAdmin()) {
            throw ValidationException::withMessages([
                'user' => ['You cannot delete another admin.'],
            ]);
        }

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }

    /**
     * Activity summary for a user.
     */
    public function activity(Request $request, User $user): JsonResponse
    {
        $user = User::withTrashed()->findOrFail($user->id);

        $recentReminders = $user->reminders()
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'title', 'type', 'due_date', 'is_completed', 'created_at']);

        $recentPets = $user->pets()
            ->withTrashed()
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['id', 'name', 'type', 'created_at', 'deleted_at']);

        return response()->json([
            'message' => 'Activity fetched successfully.',
            'summary' => [
                'pets'           => $user->petsCount(),
                'health_records' => $user->healthRecordsCount(),
                'vaccinations'   => $user->vaccinationsCount(),
                'reminders'      => $user->reminders()->count(),
            ],
            'recent' => [
                'pets'      => $recentPets,
                'reminders' => $recentReminders,
            ],
        ]);
    }
}