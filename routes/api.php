<?php

use App\Http\Controllers\Admin\AdminPetController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Health\HealthQrController;
use App\Http\Controllers\Health\HealthRecordController;
use App\Http\Controllers\Health\ReminderController;
use App\Http\Controllers\Health\VaccinationController;
use App\Http\Controllers\Pet\PetController;
use App\Http\Controllers\Pet\PetNameController;
use App\Http\Controllers\Pet\ReportPetController;
use App\Http\Controllers\PublicAccess\PublicHealthController;
use App\Http\Controllers\User\AvatarController;
use App\Http\Controllers\User\PasswordController;
use App\Http\Controllers\User\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ================= Public routes =================
Route::post('/register', RegisterController::class)->middleware('throttle:10,1');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
Route::post('/email/verify', [OtpController::class, 'verifyEmail'])->middleware('throttle:10,1');
Route::post('/email/resend-otp', [OtpController::class, 'resend'])->middleware('throttle:5,1');
Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:5,1');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1');

// -------- Public Health QR (no auth) --------
Route::get('/public/pets/{token}', [PublicHealthController::class, 'show'])
    ->middleware('throttle:60,1');

// ================= Protected routes =================
Route::middleware('auth:sanctum')->group(function () {
    // ----- User Profile -----
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    // ----- Avatar -----
    Route::post('/profile/avatar',   [AvatarController::class, 'update']);
    Route::delete('/profile/avatar', [AvatarController::class, 'destroy']);

    // ----- Password -----
    Route::put('/password', [PasswordController::class, 'update']);

    // ----- Pets -----
    Route::apiResource('pets', PetController::class);
    Route::post('/pets/{pet}/photos',           [PetController::class, 'uploadPhotos']);
    Route::delete('/pets/{pet}/photos/{photo}', [PetController::class, 'destroyPhoto']);
    Route::post('/pets/{pet}/report',           [ReportPetController::class, 'store']);

    // ----- Pet Name Generator -----
    Route::post('/pet-names/generate', [PetNameController::class, 'generate']);

    // ----- Health Records -----
    Route::get('/pets/{pet}/health-records/summary', [HealthRecordController::class, 'summary']);
    Route::post('/pets/{pet}/health-records/{health_record}/documents',
        [HealthRecordController::class, 'uploadDocuments']);
    Route::delete('/pets/{pet}/health-records/{health_record}/documents/{document}',
        [HealthRecordController::class, 'destroyDocument']);
    Route::apiResource('pets.health-records', HealthRecordController::class);

    // ----- Vaccinations -----
    Route::get('/pets/{pet}/vaccinations/upcoming', [VaccinationController::class, 'upcoming']);
    Route::apiResource('pets.vaccinations', VaccinationController::class);

    // ----- Reminders -----
    Route::get('/reminders',                       [ReminderController::class, 'index']);
    Route::get('/pets/{pet}/reminders',            [ReminderController::class, 'forPet']);
    Route::post('/pets/{pet}/reminders',           [ReminderController::class, 'store']);
    Route::get('/reminders/{reminder}',            [ReminderController::class, 'show']);
    Route::put('/reminders/{reminder}',            [ReminderController::class, 'update']);
    Route::patch('/reminders/{reminder}/complete', [ReminderController::class, 'complete']);
    Route::patch('/reminders/{reminder}/dismiss',  [ReminderController::class, 'dismiss']);
    Route::delete('/reminders/{reminder}',         [ReminderController::class, 'destroy']);

    // ----- Health QR -----
    Route::post('/pets/{pet}/health-qr',   [HealthQrController::class, 'generate']);
    Route::get('/pets/{pet}/health-qr',    [HealthQrController::class, 'show']);
    Route::delete('/pets/{pet}/health-qr', [HealthQrController::class, 'revoke']);

    // ----- Existing -----
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/logout', [LoginController::class, 'logout']);

    // ================= Admin routes =================
    Route::middleware('admin')->prefix('admin')->group(function () {
        // Users
        Route::get('/users',                    [AdminUserController::class, 'index']);
        Route::get('/users/{user}',             [AdminUserController::class, 'show']);
        Route::patch('/users/{user}/status',    [AdminUserController::class, 'updateStatus']);
        Route::delete('/users/{user}',          [AdminUserController::class, 'destroy']);
        Route::get('/users/{user}/activity',    [AdminUserController::class, 'activity']);

        // Pets
        Route::get('/pets',                     [AdminPetController::class, 'index']);
        Route::get('/pets/{pet}',               [AdminPetController::class, 'show']);
        Route::delete('/pets/{pet}',            [AdminPetController::class, 'destroy']);
        Route::patch('/pets/{id}/restore',      [AdminPetController::class, 'restore']);

        // Pet Reports
        Route::get('/pet-reports',              [AdminPetController::class, 'reports']);
        Route::patch('/pet-reports/{report}',   [AdminPetController::class, 'reviewReport']);
    });
});