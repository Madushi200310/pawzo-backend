<?php

use App\Http\Controllers\ComplaintController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function () {
        Route::post(
            '/complaints',
            [ComplaintController::class, 'store'],
        )->middleware('throttle:10,1');

        Route::get(
            '/complaints/mine',
            [ComplaintController::class, 'mine'],
        );

        Route::prefix('admin')
            ->middleware('admin')
            ->group(function () {
                Route::get(
                    '/complaints',
                    [ComplaintController::class, 'adminIndex'],
                );

                Route::get(
                    '/complaints/{complaint}',
                    [ComplaintController::class, 'adminShow'],
                )->whereNumber('complaint');

                Route::patch(
                    '/complaints/{complaint}',
                    [ComplaintController::class, 'respond'],
                )->whereNumber('complaint');
            });
    });