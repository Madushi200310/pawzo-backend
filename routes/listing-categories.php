<?php

use App\Http\Controllers\ListingCategoryController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->middleware('api')->group(function () {
    Route::get(
        '/listing-categories',
        [ListingCategoryController::class, 'index'],
    );

    Route::prefix('admin')
        ->middleware(['auth:sanctum', 'admin'])
        ->group(function () {
            Route::get(
                '/listing-categories',
                [ListingCategoryController::class, 'index'],
            );

            Route::post(
                '/listing-categories',
                [ListingCategoryController::class, 'store'],
            );

            Route::put(
                '/listing-categories/{listingCategory}',
                [ListingCategoryController::class, 'update'],
            )->whereNumber('listingCategory');

            Route::delete(
                '/listing-categories/{listingCategory}',
                [ListingCategoryController::class, 'destroy'],
            )->whereNumber('listingCategory');
        });
});