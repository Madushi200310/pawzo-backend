<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    /**
     * List users with search & filters.
     */
    public function index(Request $request)
    {
        $query = User::query();

        // Search by name or email
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ILIKE', "%{$search}%")
                  ->orWhere('email', 'ILIKE', "%{$search}%")
                  ->orWhere('phone', 'ILIKE', "%{$search}%");
            });
        }

        // Filter by role
        if ($request->filled('role')) {
            $query->where('role', $request->role);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Sort
        $sort = $request->query('sort', 'created_at');
        $direction = $request->query('direction', 'desc');
        $allowedSorts = ['id', 'name', 'email', 'role', 'is_active', 'created_at'];
        if (in_array($sort, $allowedSorts)) {
            $query->orderBy($sort, $direction === 'asc' ? 'asc' : 'desc');
        }

        return response()->json($query->paginate(30));
    }

    /**
     * Show a single user with all their activity.
     */
    public function show(User $user)
    {
        $user->loadCount([
            'businesses',
            'products',
            'petSaleListings',
        ]);

        return response()->json([
            'user' => $user,
            'activity' => [
                'businesses'      => $user->businesses()->latest()->limit(5)->get(['id', 'name', 'status', 'created_at']),
                'products'        => $user->products()->latest()->limit(5)->get(['id', 'name', 'is_approved', 'created_at']),
                'pet_sale_listings' => $user->petSaleListings()->latest()->limit(5)->get(['id', 'pet_type', 'breed', 'is_approved', 'created_at']),
                'reviews'         => $user->reviews()->latest()->limit(5)->get(['id', 'rating', 'comment', 'is_approved', 'created_at']),
                'chatbot_conversations' => $user->chatbotConversations()->latest('last_message_at')->limit(5)->get(['id', 'title', 'message_count', 'last_message_at']),
            ],
        ]);
    }

    /**
     * Activate a user.
     */
    public function activate(Request $request, User $user)
    {
        if ($user->is_active) {
            return response()->json(['message' => 'User is already active.'], 422);
        }

        $user->update(['is_active' => true]);

        return response()->json([
            'message' => "User {$user->name} activated.",
            'user'    => $user,
        ]);
    }

    /**
     * Deactivate a user (with safety checks).
     */
    public function deactivate(Request $request, User $user)
    {
        $admin = $request->user();

        // Can't deactivate self
        if ($user->id === $admin->id) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        // Can't deactivate the last active admin
        if ($user->isAdmin() && $this->activeAdminCount() <= 1) {
            return response()->json(['message' => 'Cannot deactivate the last active admin.'], 422);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'User is already inactive.'], 422);
        }

        $user->update(['is_active' => false]);

        // Revoke all tokens so the user is logged out everywhere
        $user->tokens()->delete();

        return response()->json([
            'message' => "User {$user->name} deactivated and logged out.",
            'user'    => $user,
        ]);
    }

    /**
     * Promote a user to admin.
     */
    public function makeAdmin(Request $request, User $user)
    {
        if ($user->isAdmin()) {
            return response()->json(['message' => 'User is already an admin.'], 422);
        }

        $user->update(['role' => 'admin']);

        return response()->json([
            'message' => "User {$user->name} promoted to admin.",
            'user'    => $user,
        ]);
    }

    /**
     * Demote an admin to regular user (with safety checks).
     */
    public function removeAdmin(Request $request, User $user)
    {
        $admin = $request->user();

        if (!$user->isAdmin()) {
            return response()->json(['message' => 'User is not an admin.'], 422);
        }

        if ($user->id === $admin->id) {
            return response()->json(['message' => 'You cannot remove your own admin role.'], 422);
        }

        if ($this->activeAdminCount() <= 1) {
            return response()->json(['message' => 'Cannot demote the last admin.'], 422);
        }

        $user->update(['role' => 'user']);

        return response()->json([
            'message' => "Admin role removed from {$user->name}.",
            'user'    => $user,
        ]);
    }

    /**
     * Delete a user (with safety checks).
     */
    public function destroy(Request $request, User $user)
    {
        $admin = $request->user();

        if ($user->id === $admin->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        if ($user->isAdmin() && $this->activeAdminCount() <= 1) {
            return response()->json(['message' => 'Cannot delete the last admin.'], 422);
        }

        DB::beginTransaction();
        try {
            // Revoke tokens
            $user->tokens()->delete();

            // The user's records use cascadeOnDelete → auto-cleaned
            $user->delete();

            DB::commit();

            return response()->json(['message' => "User {$user->name} deleted."]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to delete user.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * User stats.
     */
    public function stats()
    {
        return response()->json([
            'total'         => User::count(),
            'admins'        => User::where('role', 'admin')->count(),
            'regular_users' => User::where('role', 'user')->count(),
            'active'        => User::where('is_active', true)->count(),
            'inactive'      => User::where('is_active', false)->count(),
            'new_today'     => User::whereDate('created_at', today())->count(),
            'new_this_week' => User::where('created_at', '>=', now()->subWeek())->count(),
            'new_this_month'=> User::where('created_at', '>=', now()->subMonth())->count(),
        ]);
    }

    // ==================== HELPERS ====================

    protected function activeAdminCount(): int
    {
        return User::where('role', 'admin')->where('is_active', true)->count();
    }
}