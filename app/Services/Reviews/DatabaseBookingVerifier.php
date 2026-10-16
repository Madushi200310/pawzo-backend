<?php

namespace App\Services\Reviews;

use App\Models\Business;
use App\Models\PetService;
use App\Models\ServiceBooking;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DatabaseBookingVerifier implements BookingVerifier
{
    public function userHasVerifiedBooking(
        User $user,
        string $reviewableType,
        int $reviewableId,
        string $bookingReference,
    ): bool {
        if (
            ! $user->is_active
            || $user->email_verified_at === null
            || $reviewableId < 1
            || ! Str::isUuid($bookingReference)
        ) {
            return false;
        }

        if (
            ! in_array(
                $reviewableType,
                [PetService::class, Business::class],
                true,
            )
        ) {
            // Product and pet-sale reviews require a real order flow.
            return false;
        }

        if (! Schema::hasTable('service_bookings')) {
            return false;
        }

        $query = ServiceBooking::query()
            ->where('reference', $bookingReference)
            ->where('user_id', $user->id)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('completed_at', '<=', now())
            ->where('scheduled_at', '<=', now())
            ->whereNull('cancelled_at');

        if ($reviewableType === PetService::class) {
            $query->where('pet_service_id', $reviewableId);
        } else {
            $query->whereHas('service', function ($service) use (
                $reviewableId,
            ) {
                $service->where('business_id', $reviewableId);
            });
        }

        return $query->exists();
    }
}