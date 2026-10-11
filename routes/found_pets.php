<?php

use App\Http\Controllers\FoundPets\FoundPetController;
use App\Http\Middleware\EnsureFoundPetAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth:sanctum',
    EnsureFoundPetAccess::class,
    'throttle:60,1',
])
    ->prefix('found-pets')
    ->name('found-pets.')
    ->group(function () {

        // List and search lost pet reports
        Route::get('/', [FoundPetController::class, 'index'])
            ->name('index');

        // View the logged-in user's reports
        Route::get('/mine', [FoundPetController::class, 'mine'])
            ->name('mine');

        // Create a report
        Route::post('/', [FoundPetController::class, 'store'])
            ->name('store');

        // View one report
        Route::get('/{foundPet}', [FoundPetController::class, 'show'])
            ->whereNumber('foundPet')
            ->name('show');

        // Update report details
        Route::patch('/{foundPet}', [FoundPetController::class, 'update'])
            ->whereNumber('foundPet')
            ->name('update');

        // Delete a report
        Route::delete('/{foundPet}', [FoundPetController::class, 'destroy'])
            ->whereNumber('foundPet')
            ->name('destroy');

        // Update status: open, reunited, or closed
        Route::patch('/{foundPet}/status', [FoundPetController::class, 'status'])
            ->whereNumber('foundPet')
            ->name('status');

        // Upload additional photos
        Route::post('/{foundPet}/photos', [FoundPetController::class, 'addPhotos'])
            ->whereNumber('foundPet')
            ->name('photos.store');

        // Delete a photo
        Route::delete(
            '/{foundPet}/photos/{photo}',
            [FoundPetController::class, 'deletePhoto']
        )
            ->whereNumber('foundPet')
            ->whereNumber('photo')
            ->name('photos.destroy');
    });