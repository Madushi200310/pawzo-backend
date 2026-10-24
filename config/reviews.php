<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Allow reviews without booking verification
    |--------------------------------------------------------------------------
    |
    | When TRUE, the BookingVerifier accepts any user and any reference.
    | Use this ONLY in development/testing when Member 3's bookings/orders
    | tables aren't ready yet.
    |
    | Production / Pawzo PDF requirement: set to FALSE so reviews can only
    | be created after a verified booking/order.
    |
    */
    'allow_unverified' => env('ALLOW_UNVERIFIED_REVIEWS', false),

    /*
    |--------------------------------------------------------------------------
    | Booking tables
    |--------------------------------------------------------------------------
    |
    | Table names used by the DatabaseBookingVerifier. When Member 3 ships
    | the real bookings/orders tables, update these values (or rely on the
    | defaults once the tables exist).
    |
    */
    'bookings_table' => env('REVIEWS_BOOKINGS_TABLE', 'bookings'),
    'orders_table' => env('REVIEWS_ORDERS_TABLE', 'orders'),

    /*
    |--------------------------------------------------------------------------
    | Reviewer uniqueness
    |--------------------------------------------------------------------------
    |
    | Enforce one review per user per reviewable. Set FALSE only if you
    | intentionally allow multiple reviews (e.g. per-booking reviews).
    |
    */
    'unique_per_user' => true,

];