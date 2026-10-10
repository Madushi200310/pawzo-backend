<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\PetSaleListing;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    /**
     * Map of reviewable type aliases → real model classes.
     */
    protected array $typeMap = [
        'product'     => Product::class,
        'business'    => Business::class,
        'pet-sale'    => PetSaleListing::class,
    ];

    /**
     * List reviews for an item (public: approved only; owner/admin: all).
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'type'   => ['required', Rule::in(array_keys($this->typeMap))],
            'id'     => 'required|integer',
        ]);

        $modelClass = $this->typeMap[$validated['type']];

        // Verify item exists
        $model = $modelClass::findOrFail($validated['id']);

        $user = $request->user() ?? auth('sanctum')->user();
        $isAdmin = $user && $user->isAdmin();

        $query = Review::with('user:id,name')
            ->where('reviewable_type', $modelClass)
            ->where('reviewable_id', $model->id);

        if (!$isAdmin) {
            $query->approved();
        }

        $reviews = $query->latest()->paginate(20);

        return response()->json([
            'average_rating' => round($model->approvedReviews()->avg('rating') ?? 0, 2),
            'total_reviews'  => $model->approvedReviews()->count(),
            'reviews'        => $reviews,
        ]);
    }

    /**
     * Create a review for an item.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type'    => ['required', Rule::in(array_keys($this->typeMap))],
            'id'      => 'required|integer',
            'rating'  => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        $modelClass = $this->typeMap[$validated['type']];
        $model      = $modelClass::findOrFail($validated['id']);

        $user = $request->user() ?? auth('sanctum')->user();

        if (!$user) {
            return response()->json(['message' => 'Please log in to leave a review.'], 401);
        }

        // One review per user per item
        $existing = Review::where('user_id', $user->id)
            ->where('reviewable_type', $modelClass)
            ->where('reviewable_id', $model->id)
            ->exists();

        if ($existing) {
            return response()->json([
                'message' => 'You have already reviewed this item.',
            ], 422);
        }

        $review = Review::create([
            'user_id'         => $user->id,
            'reviewable_type' => $modelClass,
            'reviewable_id'   => $model->id,
            'rating'          => $validated['rating'],
            'comment'         => $validated['comment'] ?? null,
            'is_approved'     => false,
        ]);

        return response()->json([
            'message' => 'Review submitted. It will appear after admin approval.',
            'review'  => $review->load('user:id,name'),
        ], 201);
    }

    /**
     * Update own review.
     */
    public function update(Request $request, Review $review)
    {
        $user = $request->user() ?? auth('sanctum')->user();

        if (!$user || $review->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'rating'  => 'sometimes|required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:2000',
        ]);

        // Reset approval if content changes
        $validated['is_approved']      = false;
        $validated['rejection_reason'] = null;
        $validated['approved_at']      = null;
        $validated['approved_by']      = null;

        $review->update($validated);

        return response()->json([
            'message' => 'Review updated. Awaiting re-approval.',
            'review'  => $review->fresh('user:id,name'),
        ]);
    }

    /**
     * Delete own review.
     */
    public function destroy(Request $request, Review $review)
    {
        $user = $request->user() ?? auth('sanctum')->user();

        if (!$user || ($review->user_id !== $user->id && !$user->isAdmin())) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $review->delete();

        return response()->json(['message' => 'Review deleted.']);
    }

    /**
     * List my own reviews.
     */
    public function mine(Request $request)
    {
        $user = $request->user() ?? auth('sanctum')->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $reviews = Review::with('reviewable')
            ->where('user_id', $user->id)
            ->latest()
            ->paginate(20);

        return response()->json($reviews);
    }
}