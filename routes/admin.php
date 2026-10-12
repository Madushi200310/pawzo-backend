<?php

use App\Http\Controllers\Admin\AdminPetServiceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/services', [AdminPetServiceController::class, 'index'])->name('services.index');
        Route::get('/services/{petService}', [AdminPetServiceController::class, 'show'])
            ->whereNumber('petService')->name('services.show');
        Route::patch('/services/{petService}', [AdminPetServiceController::class, 'respond'])
            ->whereNumber('petService')->name('services.respond');
        Route::delete('/services/{petService}', [AdminPetServiceController::class, 'destroy'])
            ->whereNumber('petService')->name('services.destroy');
    });