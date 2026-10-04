<?php

use App\Http\Controllers\LostPets\LostPetController;
use App\Http\Middleware\EnsureLostPetAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth:sanctum',
    EnsureLostPetAccess::class,
    'throttle:60,1',
])
    ->prefix('lost-pets')
    ->name('lost-pets.')
    ->group(function () {

        // List and search lost pet reports
        Route::get('/', [LostPetController::class, 'index'])
            ->name('index');

        // View the logged-in user's reports
        Route::get('/mine', [LostPetController::class, 'mine'])
            ->name('mine');

        // Create a report
        Route::post('/', [LostPetController::class, 'store'])
            ->name('store');

        // View one report
        Route::get('/{lostPet}', [LostPetController::class, 'show'])
            ->whereNumber('lostPet')
            ->name('show');

        // Update report details
        Route::patch('/{lostPet}', [LostPetController::class, 'update'])
            ->whereNumber('lostPet')
            ->name('update');

        // Delete a report
        Route::delete('/{lostPet}', [LostPetController::class, 'destroy'])
            ->whereNumber('lostPet')
            ->name('destroy');

        // Update status: open, reunited, or closed
        Route::patch('/{lostPet}/status', [LostPetController::class, 'status'])
            ->whereNumber('lostPet')
            ->name('status');

        // Upload additional photos
        Route::post('/{lostPet}/photos', [LostPetController::class, 'addPhotos'])
            ->whereNumber('lostPet')
            ->name('photos.store');

        // Delete a photo
        Route::delete(
            '/{lostPet}/photos/{photo}',
            [LostPetController::class, 'deletePhoto']
        )
            ->whereNumber('lostPet')
            ->whereNumber('photo')
            ->name('photos.destroy');
    });