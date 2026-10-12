<?php

use App\Http\Controllers\Services\PetServiceController;
use App\Http\Middleware\EnsureLostPetAccess;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', EnsureLostPetAccess::class, 'throttle:60,1,services:'])
    ->prefix('services')
    ->name('services.')
    ->group(function () {
        Route::get('/', [PetServiceController::class, 'index'])->name('index');
        Route::get('/mine', [PetServiceController::class, 'mine'])->name('mine');
        Route::post('/', [PetServiceController::class, 'store'])->name('store');

        Route::get('/{petService}', [PetServiceController::class, 'show'])
            ->whereNumber('petService')
            ->name('show');

        Route::patch('/{petService}', [PetServiceController::class, 'update'])
            ->whereNumber('petService')
            ->name('update');

        Route::delete('/{petService}', [PetServiceController::class, 'destroy'])
            ->whereNumber('petService')
            ->name('destroy');

        Route::post('/{petService}/photos', [PetServiceController::class, 'addPhotos'])
            ->whereNumber('petService')
            ->name('photos.store');

        Route::delete('/{petService}/photos/{photo}', [PetServiceController::class, 'deletePhoto'])
            ->whereNumber('petService')
            ->whereNumber('photo')
            ->name('photos.destroy');
    });