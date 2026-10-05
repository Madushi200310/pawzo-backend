<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\Admin\BusinessVerificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::post('/register', RegisterController::class)->middleware('throttle:10,1');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
Route::post('/email/verify', [OtpController::class, 'verifyEmail'])->middleware('throttle:10,1');
Route::post('/email/resend-otp', [OtpController::class, 'resend'])->middleware('throttle:5,1');

// Public — business types (anyone can browse categories)
Route::get('/business-types', [\App\Http\Controllers\BusinessTypeController::class, 'index']);
Route::get('/business-types/{businessType}', [\App\Http\Controllers\BusinessTypeController::class, 'show']);

/*
|--------------------------------------------------------------------------
| Protected Routes (Sanctum)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    // === Auth / User ===
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/logout', [LoginController::class, 'logout']);

    /*
    |----------------------------------------------------------------------
    | Member 3 — Business Verification (user-side)
    |----------------------------------------------------------------------
    */
    Route::get('/businesses', [BusinessController::class, 'index']);
    Route::post('/businesses', [BusinessController::class, 'store']);
    Route::get('/businesses/{business}', [BusinessController::class, 'show']);
    Route::put('/businesses/{business}', [BusinessController::class, 'update']);
    Route::delete('/businesses/{business}', [BusinessController::class, 'destroy']);

    /*
    |----------------------------------------------------------------------
    | Member 3 — Admin Business Verification
    |----------------------------------------------------------------------
    | NOTE: 'admin' middleware will be added later.
    | For now, any authenticated user can access these (dev only).
    */
    Route::prefix('admin/business-verifications')->group(function () {
        Route::get('/',            [BusinessVerificationController::class, 'index']);
        Route::get('/stats',       [BusinessVerificationController::class, 'stats']);
        Route::get('/{business}',  [BusinessVerificationController::class, 'show']);
        Route::post('/{business}/approve', [BusinessVerificationController::class, 'approve']);
        Route::post('/{business}/reject',  [BusinessVerificationController::class, 'reject']);
    });
});

/*
|--------------------------------------------------------------------------
| Admin Business Types (CRUD — to be protected with 'admin' middleware)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('admin/business-types')->group(function () {
    Route::get('/',                    [\App\Http\Controllers\BusinessTypeController::class, 'index']);
    Route::post('/',                   [\App\Http\Controllers\BusinessTypeController::class, 'store']);
    Route::get('/{businessType}',      [\App\Http\Controllers\BusinessTypeController::class, 'show']);
    Route::put('/{businessType}',      [\App\Http\Controllers\BusinessTypeController::class, 'update']);
    Route::delete('/{businessType}',   [\App\Http\Controllers\BusinessTypeController::class, 'destroy']);
});