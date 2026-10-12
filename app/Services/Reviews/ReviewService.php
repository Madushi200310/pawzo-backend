<?php

namespace App\Services\Reviews;

use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReviewService
{
    public function __construct(
        private BookingVerifier $verifier
    ) {}

    /**
     * Create a review for a specific reviewable model.
     */
    public function create(
        User $user,
        string $reviewableType,
        int $reviewableId,
        string $bookingReference,
        int $rating,
        ?string $comment,
    ): Review {
        return DB::transaction(function () use (
            $user, $reviewableType, $reviewableId,
            $bookingReference, $rating, $comment
        ) {
            // One review per user per item
            $existing = Review::query()
                ->where('user_id', $user->id)
                ->where('reviewable_type', $reviewableType)
                ->where('reviewable_id', $reviewableId)
                ->exists();

            if ($existing) {
                abort(422, 'You have already reviewed this item.');
            }

            $review = Review::create([
                'user_id' => $user->id,
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
            $locked = Review::query()->lockForUpdate()->findOrFail($review->id);
            $locked->fill($data);

            // Any content change resets approval — admin must re-approve.
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
            $locked = Review::query()->lockForUpdate()->findOrFail($review->id);
            $locked->is_approved = true;
            $locked->rejection_reason = null;
            $locked->approved_at = now();
            $locked->approved_by = $admin->id;
            $locked->save();

            return $locked->load('user:id,name');
        });
    }

    public function reject(Review $review, User $admin, string $reason): Review
    {
        return DB::transaction(function () use ($review, $admin, $reason) {
            $locked = Review::query()->lockForUpdate()->findOrFail($review->id);
            $locked->is_approved = false;
            $locked->rejection_reason = $reason;
            $locked->approved_at = null;
            $locked->approved_by = $admin->id;
            $locked->save();

            return $locked->load('user:id,name');
        });
    }
}