<?php

use App\Http\Controllers\Services\ServiceBookingController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function () {
        Route::get(
            '/service-bookings/mine',
            [ServiceBookingController::class, 'mine'],
        );

        Route::get(
            '/service-bookings/incoming',
            [ServiceBookingController::class, 'incoming'],
        );

        Route::post(
            '/services/{petService}/bookings',
            [ServiceBookingController::class, 'store'],
        );

        Route::patch(
            '/service-bookings/{serviceBooking}',
            [ServiceBookingController::class, 'respond'],
        );
    });