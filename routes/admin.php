<?php

use App\Http\Controllers\Admin\AdminPetServiceController;
use App\Http\Controllers\Admin\AdminReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {

        // ============================================
        // Pet Services moderation
        // ============================================
        Route::get('/services', [AdminPetServiceController::class, 'index'])->name('services.index');
        Route::get('/services/{petService}', [AdminPetServiceController::class, 'show'])
            ->whereNumber('petService')->name('services.show');
        Route::patch('/services/{petService}', [AdminPetServiceController::class, 'respond'])
            ->whereNumber('petService')->name('services.respond');
        Route::delete('/services/{petService}', [AdminPetServiceController::class, 'destroy'])
            ->whereNumber('petService')->name('services.destroy');

        // ============================================
        // Reviews moderation
        // ============================================
        Route::prefix('reviews')->name('reviews.')->group(function () {
            Route::get('/', [AdminReviewController::class, 'index'])->name('index');
            Route::patch('/{review}/approve', [AdminReviewController::class, 'approve'])
                ->whereNumber('review')->name('approve');
            Route::patch('/{review}/reject', [AdminReviewController::class, 'reject'])
                ->whereNumber('review')->name('reject');
            Route::delete('/{review}', [AdminReviewController::class, 'destroy'])
                ->whereNumber('review')->name('destroy');
        });

                // ============================================
        // Lost Pets moderation
        // ============================================
        Route::prefix('lost-pets')->name('lost-pets.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\AdminLostPetController::class, 'index'])
                ->name('index');
            Route::get('/{lostPet}', [\App\Http\Controllers\Admin\AdminLostPetController::class, 'show'])
                ->whereNumber('lostPet')->name('show');
            Route::delete('/{lostPet}', [\App\Http\Controllers\Admin\AdminLostPetController::class, 'destroy'])
                ->whereNumber('lostPet')->name('destroy');
            Route::patch('/{lostPet}/flag', [\App\Http\Controllers\Admin\AdminLostPetController::class, 'flag'])
                ->whereNumber('lostPet')->name('flag');
            Route::patch('/{lostPet}/unflag', [\App\Http\Controllers\Admin\AdminLostPetController::class, 'unflag'])
                ->whereNumber('lostPet')->name('unflag');
        });

        // ============================================
        // Found Pets moderation
        // ============================================
        Route::prefix('found-pets')->name('found-pets.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\AdminFoundPetController::class, 'index'])
                ->name('index');
            Route::get('/{foundPet}', [\App\Http\Controllers\Admin\AdminFoundPetController::class, 'show'])
                ->whereNumber('foundPet')->name('show');
            Route::delete('/{foundPet}', [\App\Http\Controllers\Admin\AdminFoundPetController::class, 'destroy'])
                ->whereNumber('foundPet')->name('destroy');
            Route::patch('/{foundPet}/flag', [\App\Http\Controllers\Admin\AdminFoundPetController::class, 'flag'])
                ->whereNumber('foundPet')->name('flag');
            Route::patch('/{foundPet}/unflag', [\App\Http\Controllers\Admin\AdminFoundPetController::class, 'unflag'])
                ->whereNumber('foundPet')->name('unflag');
        });

        // ============================================
        // Smart Matching monitoring
        // ============================================
        Route::prefix('matches')->name('matches.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\AdminMatchController::class, 'index'])
                ->name('index');
            Route::get('/{match}', [\App\Http\Controllers\Admin\AdminMatchController::class, 'show'])
                ->whereNumber('match')->name('show');
            Route::patch('/{match}/flag', [\App\Http\Controllers\Admin\AdminMatchController::class, 'flag'])
                ->whereNumber('match')->name('flag');
            Route::patch('/{match}/unflag', [\App\Http\Controllers\Admin\AdminMatchController::class, 'unflag'])
                ->whereNumber('match')->name('unflag');
        });
        
    });