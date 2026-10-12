<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reviews\ListReviewsRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Services\Reviews\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminReviewController extends Controller
{
    public function __construct(
        private ReviewService $reviews
    ) {}

    public function index(ListReviewsRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();

        $query = Review::query()->with('user:id,name');

        if (isset($data['status']) && $data['status'] !== 'all') {
            if ($data['status'] === 'approved') {
                $query->where('is_approved', true);
            } elseif ($data['status'] === 'pending') {
                $query->where('is_approved', false)->whereNull('rejection_reason');
            } elseif ($data['status'] === 'rejected') {
                $query->whereNotNull('rejection_reason');
            }
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

        return ReviewResource::collection(
            $query->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate((int) ($data['per_page'] ?? 15))
                ->withQueryString()
        );
    }

    public function approve(Request $request, Review $review): ReviewResource
    {
        return (new ReviewResource($this->reviews->approve($review, $request->user())))
            ->additional(['message' => 'Review approved.']);
    }

    public function reject(Request $request, Review $review): ReviewResource
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        return (new ReviewResource($this->reviews->reject($review, $request->user(), $data['reason'])))
            ->additional(['message' => 'Review rejected.']);
    }

    public function destroy(Review $review, ReviewService $service): JsonResponse
    {
        $service->delete($review);

        return response()->json(['message' => 'Review removed.']);
    }
}