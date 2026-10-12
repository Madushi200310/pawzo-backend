<?php

use App\Http\Controllers\ReviewController;
use App\Http\Middleware\EnsureLostPetAccess;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reviews
|--------------------------------------------------------------------------
|
| All review routes require authentication. Unregistered users cannot
| browse reviews — that's the design decision for this module.
|
*/

Route::middleware(['auth:sanctum', EnsureLostPetAccess::class, 'throttle:60,1,reviews:'])
    ->prefix('reviews')
    ->name('reviews.')
    ->group(function () {
        Route::get('/', [ReviewController::class, 'index'])->name('index');
        Route::get('/mine', [ReviewController::class, 'mine'])->name('mine');
        Route::post('/', [ReviewController::class, 'store'])->name('store');
        Route::patch('/{review}', [ReviewController::class, 'update'])
            ->whereNumber('review')->name('update');
        Route::delete('/{review}', [ReviewController::class, 'destroy'])
            ->whereNumber('review')->name('destroy');
    });