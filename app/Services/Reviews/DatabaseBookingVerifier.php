<?php

namespace App\Services\Reviews;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verifies reviews against real bookings/orders tables.
 *
 * The verifier is defensive: if neither table exists, it returns FALSE
 * (so reviews are blocked until Member 3 ships the tables). This means
 * flipping config('reviews.allow_unverified') to false is enough to turn
 * real verification on — no code change required.
 */
class DatabaseBookingVerifier implements BookingVerifier
{
    public function userHasVerifiedBooking(
        User $user,
        string $reviewableType,
        int $reviewableId,
        string $bookingReference,
    ): bool {
        if ($bookingReference === '') {
            return false;
        }

        $bookingsTable = (string) config('reviews.bookings_table', 'bookings');
        $ordersTable = (string) config('reviews.orders_table', 'orders');

        // Try bookings first (services).
        if (Schema::hasTable($bookingsTable)) {
            $exists = DB::table($bookingsTable)
                ->where('id', $bookingReference)
                ->where('user_id', $user->id)
                ->where('status', 'completed')
                ->exists();

            if ($exists) {
                return true;
            }
        }

        // Then orders (products).
        if (Schema::hasTable($ordersTable)) {
            $exists = DB::table($ordersTable)
                ->where('id', $bookingReference)
                ->where('user_id', $user->id)
                ->where('status', 'completed')
                ->exists();

            if ($exists) {
                return true;
            }
        }

        return false;
    }
}