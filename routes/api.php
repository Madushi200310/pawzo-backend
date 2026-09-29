<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Pet\PetController;
use App\Http\Controllers\User\AvatarController;
use App\Http\Controllers\User\PasswordController;
use App\Http\Controllers\User\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ---------------- Public routes ----------------
Route::post('/register', RegisterController::class)->middleware('throttle:10,1');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
Route::post('/email/verify', [OtpController::class, 'verifyEmail'])->middleware('throttle:10,1');
Route::post('/email/resend-otp', [OtpController::class, 'resend'])->middleware('throttle:5,1');
Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:5,1');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1');

// ---------------- Protected routes ----------------
Route::middleware('auth:sanctum')->group(function () {
    // ----- User Profile (Member 1) -----
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    // ----- Avatar (Member 1) -----
    Route::post('/profile/avatar',   [AvatarController::class, 'update']);
    Route::delete('/profile/avatar', [AvatarController::class, 'destroy']);

    // ----- Password (Member 1) -----
    Route::put('/password', [PasswordController::class, 'update']);

    // ----- Pets (Member 1) -----
    Route::apiResource('pets', PetController::class);
    Route::post('/pets/{pet}/photos',              [PetController::class, 'uploadPhotos']);
    Route::delete('/pets/{pet}/photos/{photo}',    [PetController::class, 'destroyPhoto']);

    // ----- Existing -----
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/logout', [LoginController::class, 'logout']);
});