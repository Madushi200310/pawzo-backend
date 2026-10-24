<?php

namespace App\Services\Reviews;

use App\Models\Review;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ReviewService
{
    public function __construct(
        private BookingVerifier $verifier,
    ) {}

    public function create(
        User $user,
        string $reviewableType,
        int $reviewableId,
        string $bookingReference,
        int $rating,
        ?string $comment,
    ): Review {
        return DB::transaction(function () use (
            $user,
            $reviewableType,
            $reviewableId,
            $bookingReference,
            $rating,
            $comment,
        ) {
            // Serialize review creation for the same customer.
            $reviewer = User::query()
                ->lockForUpdate()
                ->findOrFail($user->id);

            abort_unless(
                $reviewer->is_active
                    && $reviewer->email_verified_at !== null,
                403,
                'An active account with a verified email is required.',
            );

            abort_unless(
                $rating >= 1 && $rating <= 5,
                422,
                'Rating must be between 1 and 5.',
            );

            if (! $this->verifier->userHasVerifiedBooking(
                $reviewer,
                $reviewableType,
                $reviewableId,
                $bookingReference,
            )) {
                throw new HttpResponseException(
                    response()->json([
                        'message' => 'You can only review items you have a verified booking/order for.',
                        'error' => 'unverified_booking',
                    ], 422)
                );
            }

            $existing = Review::query()
                ->where('user_id', $reviewer->id)
                ->where('reviewable_type', $reviewableType)
                ->where('reviewable_id', $reviewableId)
                ->exists();

            if ($existing) {
                abort(422, 'You have already reviewed this item.');
            }

            $review = Review::create([
                'user_id' => $reviewer->id,
                'reviewable_type' => $reviewableType,
                'reviewable_id' => $reviewableId,
                'rating' => $rating,
                'comment' => $comment,
                'is_approved' => false,
            ]);

            return $review->load('user:id,name');
        });
    }

    public function update(Review $review, array $data): Review
    {
        return DB::transaction(function () use ($review, $data) {
            $locked = Review::query()
                ->lockForUpdate()
                ->findOrFail($review->id);

            $locked->fill(
                Arr::only($data, ['rating', 'comment'])
            );

            $locked->is_approved = false;
            $locked->rejection_reason = null;
            $locked->approved_at = null;
            $locked->approved_by = null;
            $locked->save();

            return $locked->load('user:id,name');
        });
    }

    public function delete(Review $review): void
    {
        $review->delete();
    }

    public function approve(Review $review, User $admin): Review
    {
        return DB::transaction(function () use ($review, $admin) {
            $locked = Review::query()
                ->lockForUpdate()
                ->findOrFail($review->id);

            $locked->is_approved = true;
            $locked->rejection_reason = null;
            $locked->approved_at = now();
            $locked->approved_by = $admin->id;
            $locked->save();

            return $locked->load('user:id,name');
        });
    }

    public function reject(
        Review $review,
        User $admin,
        string $reason,
    ): Review {
        return DB::transaction(function () use (
            $review,
            $admin,
            $reason,
        ) {
            $locked = Review::query()
                ->lockForUpdate()
                ->findOrFail($review->id);

            $locked->is_approved = false;
            $locked->rejection_reason = $reason;
            $locked->approved_at = null;
            $locked->approved_by = $admin->id;
            $locked->save();

            return $locked->load('user:id,name');
        });
    }
}