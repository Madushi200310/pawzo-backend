<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reviews\ListReviewsRequest;
use App\Http\Requests\Reviews\SaveReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Services\Reviews\BookingVerifier;
use App\Services\Reviews\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class ReviewController extends Controller
{
    public function __construct(
        private ReviewService $reviews,
        private BookingVerifier $verifier,
    ) {}

    /**
     * Public list of reviews for a reviewable item.
     * Unregistered users can browse. Admin sees all; others see approved only.
     */
    public function index(ListReviewsRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (empty($data['type']) || empty($data['id'])) {
            abort(422, 'Both `type` and `id` are required to list reviews.');
        }

        $modelClass = SaveReviewRequest::resolveType($data['type']);
        $modelClass::findOrFail($data['id']);

        $viewer = $request->user();
        $isAdmin = $viewer && $viewer->role === 'admin';

        $query = Review::query()
            ->with('user:id,name')
            ->where('reviewable_type', $modelClass)
            ->where('reviewable_id', $data['id']);

        if (! $isAdmin) {
            $query->where('is_approved', true);
        } elseif (isset($data['status']) && $data['status'] !== 'all') {
            match ($data['status']) {
                'approved' => $query->where('is_approved', true),
                'pending' => $query->where('is_approved', false)->whereNull('rejection_reason'),
                'rejected' => $query->whereNotNull('rejection_reason'),
                default => null,
            };
        }

        if (isset($data['min_rating'])) {
            $query->where('rating', '>=', $data['min_rating']);
        }
        if (isset($data['max_rating'])) {
            $query->where('rating', '<=', $data['max_rating']);
        }
        if (isset($data['q'])) {
            $term = '%'.mb_strtolower($data['q']).'%';
            $query->whereRaw('LOWER(comment) LIKE ?', [$term]);
        }

        $avg = (clone $query)->avg('rating');
        $count = (clone $query)->count();

        $paginated = $query->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 15))
            ->withQueryString();

        return response()->json([
            'average_rating' => round((float) ($avg ?? 0), 2),
            'total_reviews' => $count,
            'reviews' => ReviewResource::collection($paginated)->response()->getData(true),
        ]);
    }

    /**
     * List the current user's own reviews.
     */
    public function mine(ListReviewsRequest $request): AnonymousResourceCollection
    {
        return ReviewResource::collection(
            Review::with(['user:id,name'])
                ->where('user_id', $request->user()->id)
                ->orderByDesc('created_at')
                ->paginate((int) ($request->validated()['per_page'] ?? 15))
                ->withQueryString()
        );
    }

    /**
     * Create a review. Requires a verified booking/order reference (Pawzo PDF rule).
     */
    public function store(SaveReviewRequest $request): JsonResponse
    {
        $data = $request->validated();

        $modelClass = SaveReviewRequest::resolveType($data['type']);
        $modelClass::findOrFail($data['id']);

        $verified = $this->verifier->userHasVerifiedBooking(
            $request->user(),
            $modelClass,
            (int) $data['id'],
            $data['booking_reference'],
        );

        if (! $verified) {
            return response()->json([
                'message' => 'You can only review items you have a verified booking/order for.',
                'error' => 'unverified_booking',
            ], 422);
        }

        $review = $this->reviews->create(
            user: $request->user(),
            reviewableType: $modelClass,
            reviewableId: (int) $data['id'],
            bookingReference: $data['booking_reference'],
            rating: (int) $data['rating'],
            comment: $data['comment'] ?? null,
        );

        return (new ReviewResource($review))
            ->additional(['message' => 'Review submitted. Awaiting admin approval.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(SaveReviewRequest $request, Review $review): ReviewResource
    {
        $updated = $this->reviews->update($review, $request->validated());

        return (new ReviewResource($updated))
            ->additional(['message' => 'Review updated. Awaiting re-approval.']);
    }

    public function destroy(Request $request, Review $review): JsonResponse
    {
        Gate::authorize('delete', $review);
        $this->reviews->delete($review);

        return response()->json(['message' => 'Review deleted.']);
    }
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