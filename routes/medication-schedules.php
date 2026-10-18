<?php

use App\Http\Controllers\Health\MedicationScheduleController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function () {
        Route::get(
            '/health-records/{healthRecord}/medication-schedule',
            [MedicationScheduleController::class, 'show'],
        )->whereNumber('healthRecord');

        Route::post(
            '/health-records/{healthRecord}/medication-schedule',
            [MedicationScheduleController::class, 'store'],
        )->whereNumber('healthRecord');

        Route::patch(
            '/health-records/{healthRecord}/medication-schedule',
            [MedicationScheduleController::class, 'update'],
        )->whereNumber('healthRecord');

        Route::delete(
            '/health-records/{healthRecord}/medication-schedule',
            [MedicationScheduleController::class, 'destroy'],
        )->whereNumber('healthRecord');
    });