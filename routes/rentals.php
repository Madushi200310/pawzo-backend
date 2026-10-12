<?php

use App\Http\Controllers\Rentals\PetRentalController;
use App\Http\Controllers\Rentals\PetRentalRequestController;
use App\Http\Middleware\EnsurePetRentalAccess;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Pet Rentals — Owner-side listing management
|--------------------------------------------------------------------------
|
| GET    /api/rentals                       — public browse (active only)
| GET    /api/rentals/mine                  — owner's own listings
| POST   /api/rentals                       — create listing
| GET    /api/rentals/{id}                  — show
| PATCH  /api/rentals/{id}                  — update
| DELETE /api/rentals/{id}                  — delete
| POST   /api/rentals/{id}/photos           — add photo
| DELETE /api/rentals/{id}/photos/{photo}   — delete photo
| GET    /api/rentals/{id}/requests         — owner views incoming requests
| POST   /api/rentals/{id}/requests         — renter sends request
|
*/
Route::middleware(['auth:sanctum', EnsurePetRentalAccess::class, 'throttle:60,1,rentals:'])
    ->prefix('rentals')
    ->name('rentals.')
    ->group(function () {
        Route::get('/', [PetRentalController::class, 'index'])->name('index');
        Route::get('/mine', [PetRentalController::class, 'mine'])->name('mine');
        Route::post('/', [PetRentalController::class, 'store'])->name('store');

        Route::get('/{petRental}', [PetRentalController::class, 'show'])
            ->whereNumber('petRental')
            ->name('show');

        Route::patch('/{petRental}', [PetRentalController::class, 'update'])
            ->whereNumber('petRental')
            ->name('update');

        Route::delete('/{petRental}', [PetRentalController::class, 'destroy'])
            ->whereNumber('petRental')
            ->name('destroy');

        Route::post('/{petRental}/photos', [PetRentalController::class, 'addPhotos'])
            ->whereNumber('petRental')
            ->name('photos.store');

        Route::delete('/{petRental}/photos/{photo}', [PetRentalController::class, 'deletePhoto'])
            ->whereNumber('petRental')
            ->whereNumber('photo')
            ->name('photos.destroy');

        // Requests scoped to a specific rental (owner's view + renter's create)
        Route::get('/{petRental}/requests', [PetRentalRequestController::class, 'index'])
            ->whereNumber('petRental')
            ->name('requests.index');

        Route::post('/{petRental}/requests', [PetRentalRequestController::class, 'store'])
            ->whereNumber('petRental')
            ->name('requests.store');
    });

/*
|--------------------------------------------------------------------------
| Pet Rental Requests — Renter-side actions
|--------------------------------------------------------------------------
|
| GET    /api/rental-requests/mine          — requests the current user sent
| PATCH  /api/rental-requests/{id}          — accept / reject / cancel
|
*/
Route::middleware(['auth:sanctum', EnsurePetRentalAccess::class, 'throttle:60,1,rental-requests:'])
    ->prefix('rental-requests')
    ->name('rental-requests.')
    ->group(function () {
        Route::get('/mine', [PetRentalRequestController::class, 'mine'])
            ->name('mine');

        Route::patch('/{petRentalRequest}', [PetRentalRequestController::class, 'respond'])
            ->whereNumber('petRentalRequest')
            ->name('respond');
    });