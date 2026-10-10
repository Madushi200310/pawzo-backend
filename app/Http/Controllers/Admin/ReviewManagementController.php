<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewManagementController extends Controller
{
    /**
     * List reviews for moderation.
     */
    public function index(Request $request)
    {
        $status = $request->query('status', 'pending');

        $query = Review::with(['user:id,name,email', 'reviewable']);

        if ($status === 'pending') {
            $query->where('is_approved', false)->whereNull('rejection_reason');
        } elseif ($status === 'approved') {
            $query->where('is_approved', true);
        } elseif ($status === 'rejected') {
            $query->whereNotNull('rejection_reason');
        }

        if ($request->filled('rating')) {
            $query->where('rating', $request->rating);
        }

        return response()->json($query->latest()->paginate(30));
    }

    public function show(Review $review)
    {
        return response()->json([
            'data' => $review->load(['user', 'reviewable', 'approvedBy:id,name']),
        ]);
    }

    public function approve(Request $request, Review $review)
    {
        $admin = $request->user();

        $review->update([
            'is_approved'      => true,
            'rejection_reason' => null,
            'approved_at'      => now(),
            'approved_by'      => $admin->id,
        ]);

        return response()->json([
            'message' => 'Review approved.',
            'review'  => $review->fresh(['user:id,name', 'approvedBy:id,name']),
        ]);
    }

    public function reject(Request $request, Review $review)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $review->update([
            'is_approved'      => false,
            'rejection_reason' => $validated['reason'],
            'approved_at'      => null,
            'approved_by'      => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Review rejected.',
            'review'  => $review->fresh(['user:id,name']),
        ]);
    }

    public function destroy(Review $review)
    {
        $review->delete();

        return response()->json(['message' => 'Review deleted.']);
    }

    public function stats()
    {
        return response()->json([
            'total'    => Review::count(),
            'pending'  => Review::where('is_approved', false)->whereNull('rejection_reason')->count(),
            'approved' => Review::where('is_approved', true)->count(),
            'rejected' => Review::whereNotNull('rejection_reason')->count(),
            'by_rating' => [
                '5' => Review::where('rating', 5)->where('is_approved', true)->count(),
                '4' => Review::where('rating', 4)->where('is_approved', true)->count(),
                '3' => Review::where('rating', 3)->where('is_approved', true)->count(),
                '2' => Review::where('rating', 2)->where('is_approved', true)->count(),
                '1' => Review::where('rating', 1)->where('is_approved', true)->count(),
            ],
            'average_rating' => round(Review::where('is_approved', true)->avg('rating') ?? 0, 2),
        ]);
    }
}