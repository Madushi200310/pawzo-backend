<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AdminProfileController extends Controller
{
    /**
     * Show own admin profile.
     */
    public function show(Request $request)
    {
        $admin = $request->user();

        return response()->json([
            'user' => $admin,
            'stats' => [
                'total_tokens'   => $admin->tokens()->count(),
                'joined_at'      => $admin->created_at,
                'last_updated'   => $admin->updated_at,
            ],
        ]);
    }

    /**
     * Update own admin profile.
     */
    public function update(Request $request)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'name'   => 'sometimes|required|string|max:255',
            'phone'  => 'nullable|string|max:20',
            'avatar' => 'nullable|url|max:500',
        ]);

        $admin->update($validated);

        return response()->json([
            'message' => 'Profile updated.',
            'user'    => $admin->fresh(),
        ]);
    }

    /**
     * Change own password.
     */
    public function changePassword(Request $request)
    {
        $admin = $request->user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password'     => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        // Verify current password
        if (!Hash::check($validated['current_password'], $admin->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        // Can't reuse same password
        if (Hash::check($validated['new_password'], $admin->password)) {
            return response()->json([
                'message' => 'New password must be different from current password.',
            ], 422);
        }

        $admin->update([
            'password' => Hash::make($validated['new_password']),
        ]);

        // Revoke all other tokens for security (keep current one)
        $currentTokenId = $request->user()->currentAccessToken()->id;
        $admin->tokens()->where('id', '!=', $currentTokenId)->delete();

        return response()->json([
            'message' => 'Password changed. All other sessions logged out.',
        ]);
    }

    /**
     * Logout from all devices.
     */
    public function logoutAll(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Logged out from all devices.',
        ]);
    }

    /**
     * View own recent activity (last N actions).
     */
    public function activity(Request $request)
    {
        $admin = $request->user();

        // Simple approach: combine recent records this admin approved/moderated
        $recentActivity = collect();

        // Approved businesses
        if (class_exists(\App\Models\Business::class)) {
            $businesses = \App\Models\Business::where('approved_by', $admin->id)
                ->latest('approved_at')
                ->limit(10)
                ->get(['id', 'name', 'status', 'approved_at'])
                ->map(fn ($b) => [
                    'type'    => 'business_approved',
                    'label'   => "Approved business: {$b->name}",
                    'at'      => $b->approved_at,
                    'details' => $b,
                ]);
            $recentActivity = $recentActivity->merge($businesses);
        }

        // Approved products
        if (class_exists(\App\Models\Product::class)) {
            $products = \App\Models\Product::where('is_approved', true)
                ->latest('updated_at')
                ->limit(10)
                ->get(['id', 'name', 'updated_at'])
                ->map(fn ($p) => [
                    'type'    => 'product_updated',
                    'label'   => "Product activity: {$p->name}",
                    'at'      => $p->updated_at,
                    'details' => $p,
                ]);
            $recentActivity = $recentActivity->merge($products);
        }

        // Approved reviews
        if (class_exists(\App\Models\Review::class)) {
            $reviews = \App\Models\Review::where('approved_by', $admin->id)
                ->latest('approved_at')
                ->limit(10)
                ->get(['id', 'rating', 'approved_at'])
                ->map(fn ($r) => [
                    'type'    => 'review_approved',
                    'label'   => "Approved review #{$r->id} ({$r->rating}★)",
                    'at'      => $r->approved_at,
                    'details' => $r,
                ]);
            $recentActivity = $recentActivity->merge($reviews);
        }

        // Sort by 'at' descending
        $sorted = $recentActivity
            ->filter(fn ($item) => $item['at'] !== null)
            ->sortByDesc('at')
            ->values()
            ->take(20);

        return response()->json([
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
            'activity' => $sorted,
        ]);
    }
}