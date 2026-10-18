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
     * List reviews for a reviewable item.
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
                'pending' => $query
                    ->where('is_approved', false)
                    ->whereNull('rejection_reason'),
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

        $average = (clone $query)->avg('rating');
        $count = (clone $query)->count();

        $paginated = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 15))
            ->withQueryString();

        return response()->json([
            'average_rating' => round((float) ($average ?? 0), 2),
            'total_reviews' => $count,
            'reviews' => ReviewResource::collection($paginated)
                ->response()
                ->getData(true),
        ]);
    }

    /**
     * List the current user's own reviews.
     */
    public function mine(
        ListReviewsRequest $request
    ): AnonymousResourceCollection {
        $data = $request->validated();

        $reviews = Review::query()
            ->with('user:id,name')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 15))
            ->withQueryString();

        return ReviewResource::collection($reviews);
    }

    /**
     * Create a review after verifying the booking or order.
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
            ->additional([
                'message' => 'Review submitted. Awaiting admin approval.',
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update the current user's review.
     */
    public function update(
        SaveReviewRequest $request,
        Review $review
    ): ReviewResource {
        Gate::authorize('update', $review);

        $updated = $this->reviews->update(
            $review,
            $request->validated(),
        );

        return (new ReviewResource($updated))
            ->additional([
                'message' => 'Review updated. Awaiting re-approval.',
            ]);
    }

    /**
     * Delete a review when permitted by the policy.
     */
    public function destroy(
        Request $request,
        Review $review
    ): JsonResponse {
        Gate::authorize('delete', $review);

        $this->reviews->delete($review);

        return response()->json([
            'message' => 'Review deleted.',
        ]);
    }
}