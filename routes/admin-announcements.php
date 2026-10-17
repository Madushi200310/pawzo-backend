<?php

use App\Http\Controllers\Admin\AdminAnnouncementController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/admin')
    ->middleware(['api', 'auth:sanctum', 'admin'])
    ->group(function () {
        Route::get(
            '/announcements',
            [AdminAnnouncementController::class, 'index'],
        );

        Route::post(
            '/announcements',
            [AdminAnnouncementController::class, 'store'],
        )->middleware('throttle:5,1');
    });