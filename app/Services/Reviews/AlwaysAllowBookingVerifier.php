<?php

namespace App\Services\Reviews;

use App\Models\User;

/**
 * Fallback verifier used when config('reviews.allow_unverified') is TRUE.
 *
 * Its ONLY purpose is to unblock the module while Member 3's bookings/orders
 * tables are being built. In production this class MUST NOT be bound.
 */
class AlwaysAllowBookingVerifier implements BookingVerifier
{
    public function userHasVerifiedBooking(
        User $user,
        string $reviewableType,
        int $reviewableId,
        string $bookingReference,
    ): bool {
        return true;
    }
}