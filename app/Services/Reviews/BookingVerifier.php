<?php

namespace App\Services\Reviews;

use App\Models\User;

interface BookingVerifier
{
    /**
     * Return TRUE when $user has a verified booking/order that lets them
     * review the given target.
     *
     * @param  User    $user              reviewer
     * @param  string  $reviewableType    FQCN of the target model
     * @param  int     $reviewableId      target model id
     * @param  string  $bookingReference  client-supplied booking/order reference
     */
    public function userHasVerifiedBooking(
        User $user,
        string $reviewableType,
        int $reviewableId,
        string $bookingReference,
    ): bool;
}