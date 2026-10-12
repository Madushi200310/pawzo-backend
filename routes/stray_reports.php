<?php

use App\Http\Controllers\StrayReports\StrayReportController;
use App\Http\Middleware\EnsureLostPetAccess;
use Illuminate\Support\Facades\Route;

// Included from routes/api.php, so /api is already applied.
Route::middleware(['auth:sanctum', EnsureLostPetAccess::class, 'throttle:60,1,stray-reports:'])
    ->prefix('stray-reports')->name('stray-reports.')->group(function () {
        Route::get('/', [StrayReportController::class, 'index'])->name('index');
        Route::get('/mine', [StrayReportController::class, 'mine'])->name('mine');
        Route::post('/', [StrayReportController::class, 'store'])->name('store');
        Route::get('/{strayReport}', [StrayReportController::class, 'show'])->whereNumber('strayReport')->name('show');
        Route::patch('/{strayReport}', [StrayReportController::class, 'update'])->whereNumber('strayReport')->name('update');
        Route::delete('/{strayReport}', [StrayReportController::class, 'destroy'])->whereNumber('strayReport')->name('destroy');
        Route::patch('/{strayReport}/status', [StrayReportController::class, 'status'])->whereNumber('strayReport')->name('status');
        Route::post('/{strayReport}/photos', [StrayReportController::class, 'addPhotos'])->whereNumber('strayReport')->name('photos.store');
        Route::delete('/{strayReport}/photos/{photo}', [StrayReportController::class, 'deletePhoto'])
            ->whereNumber('strayReport')->whereNumber('photo')->name('photos.destroy');
    });