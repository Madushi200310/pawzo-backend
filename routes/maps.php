<?php

use App\Http\Controllers\Maps\MapReportsController;
use App\Http\Middleware\EnsureLostPetAccess;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth:sanctum',
    EnsureLostPetAccess::class,
    'throttle:60,1,maps:',
])->group(function () {
    Route::get('/map/reports', MapReportsController::class)
        ->name('maps.reports.index');
});