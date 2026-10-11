<?php

use App\Http\Controllers\Matching\PetMatchingController;
use App\Http\Middleware\EnsureLostPetAccess;
use Illuminate\Support\Facades\Route;

// Reuse the existing active-user and email-verification middleware.
Route::middleware([
    'auth:sanctum',
    EnsureLostPetAccess::class,
    'throttle:60,1',
])->group(function () {
    Route::get(
        '/lost-pets/{lostPet}/matches',
        [PetMatchingController::class, 'lost']
    )
        ->whereNumber('lostPet')
        ->name('matching.lost.index');

    Route::post(
        '/lost-pets/{lostPet}/matches/refresh',
        [PetMatchingController::class, 'refreshLost']
    )
        ->whereNumber('lostPet')
        ->middleware('throttle:5,1,matching-refresh:')
        ->name('matching.lost.refresh');

    Route::get(
        '/found-pets/{foundPet}/matches',
        [PetMatchingController::class, 'found']
    )
        ->whereNumber('foundPet')
        ->name('matching.found.index');

    Route::post(
        '/found-pets/{foundPet}/matches/refresh',
        [PetMatchingController::class, 'refreshFound']
    )
        ->whereNumber('foundPet')
        ->middleware('throttle:5,1,matching-refresh:')
        ->name('matching.found.refresh');
});