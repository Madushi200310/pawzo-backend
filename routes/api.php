<?php

use App\Http\Controllers\Admin\AdminPetController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\BusinessVerificationController;
use App\Http\Controllers\Admin\ChatbotMonitoringController;
use App\Http\Controllers\Admin\PetSaleListingApprovalController;
use App\Http\Controllers\Admin\ProductApprovalController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\BusinessTypeController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\Health\HealthQrController;
use App\Http\Controllers\Health\HealthRecordController;
use App\Http\Controllers\Health\ReminderController;
use App\Http\Controllers\Health\VaccinationController;
use App\Http\Controllers\Pet\PetController;
use App\Http\Controllers\Pet\PetNameController;
use App\Http\Controllers\Pet\ReportPetController;
use App\Http\Controllers\PetSaleListingController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PublicAccess\PublicHealthController;
use App\Http\Controllers\User\AvatarController;
use App\Http\Controllers\User\PasswordController;
use App\Http\Controllers\User\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES
|--------------------------------------------------------------------------
*/

// ---------- Auth ----------
Route::post('/register', RegisterController::class)->middleware('throttle:10,1');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
Route::post('/email/verify', [OtpController::class, 'verifyEmail'])->middleware('throttle:10,1');
Route::post('/email/resend-otp', [OtpController::class, 'resend'])->middleware('throttle:5,1');
Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:5,1');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1');

// ---------- Social Login ----------
Route::get('/auth/google/redirect',   [SocialAuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback',   [SocialAuthController::class, 'handleGoogleCallback']);
Route::get('/auth/facebook/redirect', [SocialAuthController::class, 'redirectToFacebook']);
Route::get('/auth/facebook/callback', [SocialAuthController::class, 'handleFacebookCallback']);

// ---------- Public Health QR ----------
Route::get('/public/pets/{token}', [PublicHealthController::class, 'show'])
    ->middleware('throttle:60,1');

// ---------- Public Browse: Business Types ----------
Route::get('/business-types',                [BusinessTypeController::class, 'index']);
Route::get('/business-types/{businessType}', [BusinessTypeController::class, 'show']);

// ---------- Public Browse: Product Categories ----------
Route::get('/product-categories',                    [ProductCategoryController::class, 'index']);
Route::get('/product-categories/{productCategory}',  [ProductCategoryController::class, 'show']);

// ---------- Public Browse: Products ----------
Route::get('/products',           [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);

// ---------- Public Browse: Pet Sale Listings ----------
Route::get('/pet-sales',                      [PetSaleListingController::class, 'index']);
Route::get('/pet-sales/{petSaleListing}',     [PetSaleListingController::class, 'show']);

// ---------- AI Chatbot (works for guests and authenticated users) ----------
Route::post('/chatbot/ask', [ChatbotController::class, 'ask'])->middleware('throttle:30,1');
Route::get('/chatbot/conversations',                   [ChatbotController::class, 'conversations']);
Route::get('/chatbot/conversations/{conversation}',    [ChatbotController::class, 'show']);
Route::delete('/chatbot/conversations/{conversation}', [ChatbotController::class, 'destroy']);

/*
|--------------------------------------------------------------------------
| PROTECTED ROUTES (Sanctum)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    // ================= Member 1 — Auth / User =================
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/logout', [LoginController::class, 'logout']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    Route::post('/profile/avatar',   [AvatarController::class, 'update']);
    Route::delete('/profile/avatar', [AvatarController::class, 'destroy']);

    Route::put('/password', [PasswordController::class, 'update']);

    // ================= Member 1 — Pets =================
    Route::apiResource('pets', PetController::class);
    Route::post('/pets/{pet}/photos',           [PetController::class, 'uploadPhotos']);
    Route::delete('/pets/{pet}/photos/{photo}', [PetController::class, 'destroyPhoto']);
    Route::post('/pets/{pet}/report',           [ReportPetController::class, 'store']);

    Route::post('/pet-names/generate', [PetNameController::class, 'generate']);

    // ================= Member 1 — Health Records =================
    Route::get('/pets/{pet}/health-records/summary', [HealthRecordController::class, 'summary']);
    Route::post('/pets/{pet}/health-records/{health_record}/documents',
        [HealthRecordController::class, 'uploadDocuments']);
    Route::delete('/pets/{pet}/health-records/{health_record}/documents/{document}',
        [HealthRecordController::class, 'destroyDocument']);
    Route::apiResource('pets.health-records', HealthRecordController::class);

    // ================= Member 1 — Vaccinations =================
    Route::get('/pets/{pet}/vaccinations/upcoming', [VaccinationController::class, 'upcoming']);
    Route::apiResource('pets.vaccinations', VaccinationController::class);

    // ================= Member 1 — Reminders =================
    Route::get('/reminders',                       [ReminderController::class, 'index']);
    Route::get('/pets/{pet}/reminders',            [ReminderController::class, 'forPet']);
    Route::post('/pets/{pet}/reminders',           [ReminderController::class, 'store']);
    Route::get('/reminders/{reminder}',            [ReminderController::class, 'show']);
    Route::put('/reminders/{reminder}',            [ReminderController::class, 'update']);
    Route::patch('/reminders/{reminder}/complete', [ReminderController::class, 'complete']);
    Route::patch('/reminders/{reminder}/dismiss',  [ReminderController::class, 'dismiss']);
    Route::delete('/reminders/{reminder}',         [ReminderController::class, 'destroy']);

    // ================= Member 1 — Health QR =================
    Route::post('/pets/{pet}/health-qr',   [HealthQrController::class, 'generate']);
    Route::get('/pets/{pet}/health-qr',    [HealthQrController::class, 'show']);
    Route::delete('/pets/{pet}/health-qr', [HealthQrController::class, 'revoke']);

    // ================= Member 3 — Businesses (user-side) =================
    Route::get('/businesses',                [BusinessController::class, 'index']);
    Route::post('/businesses',               [BusinessController::class, 'store']);
    Route::get('/businesses/{business}',     [BusinessController::class, 'show']);
    Route::put('/businesses/{business}',     [BusinessController::class, 'update']);
    Route::delete('/businesses/{business}',  [BusinessController::class, 'destroy']);

    // ================= Member 3 — Products (seller CRUD) =================
    Route::post('/products',           [ProductController::class, 'store']);
    Route::put('/products/{product}',  [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);

    // ================= Member 3 — Pet Sale Listings (seller CRUD) =================
    Route::post('/pet-sales',                     [PetSaleListingController::class, 'store']);
    Route::put('/pet-sales/{petSaleListing}',     [PetSaleListingController::class, 'update']);
    Route::delete('/pet-sales/{petSaleListing}',  [PetSaleListingController::class, 'destroy']);

    /*
    |----------------------------------------------------------------------
    | ADMIN ROUTES (admin middleware)
    |----------------------------------------------------------------------
    */
    Route::middleware('admin')->prefix('admin')->group(function () {

        // ===== Member 1 — Users =====
        Route::get('/users',                 [AdminUserController::class, 'index']);
        Route::get('/users/{user}',          [AdminUserController::class, 'show']);
        Route::patch('/users/{user}/status', [AdminUserController::class, 'updateStatus']);
        Route::delete('/users/{user}',       [AdminUserController::class, 'destroy']);
        Route::get('/users/{user}/activity', [AdminUserController::class, 'activity']);

        // ===== Member 1 — Pets =====
        Route::get('/pets',                [AdminPetController::class, 'index']);
        Route::get('/pets/{pet}',          [AdminPetController::class, 'show']);
        Route::delete('/pets/{pet}',       [AdminPetController::class, 'destroy']);
        Route::patch('/pets/{id}/restore', [AdminPetController::class, 'restore']);

        // ===== Member 1 — Pet Reports =====
        Route::get('/pet-reports',            [AdminPetController::class, 'reports']);
        Route::patch('/pet-reports/{report}', [AdminPetController::class, 'reviewReport']);

        // ===== Member 3 — Business Verifications =====
        Route::prefix('business-verifications')->group(function () {
            Route::get('/',       [BusinessVerificationController::class, 'index']);
            Route::get('/stats',  [BusinessVerificationController::class, 'stats']);
            Route::get('/{business}', [BusinessVerificationController::class, 'show']);
            Route::post('/{business}/approve', [BusinessVerificationController::class, 'approve']);
            Route::post('/{business}/reject',  [BusinessVerificationController::class, 'reject']);
        });

        // ===== Member 3 — Business Types CRUD =====
        Route::prefix('business-types')->group(function () {
            Route::get('/',                  [BusinessTypeController::class, 'index']);
            Route::post('/',                 [BusinessTypeController::class, 'store']);
            Route::get('/{businessType}',    [BusinessTypeController::class, 'show']);
            Route::put('/{businessType}',    [BusinessTypeController::class, 'update']);
            Route::delete('/{businessType}', [BusinessTypeController::class, 'destroy']);
        });

        // ===== Member 3 — Product Categories =====
        Route::prefix('product-categories')->group(function () {
            Route::get('/',                     [ProductCategoryController::class, 'index']);
            Route::post('/',                    [ProductCategoryController::class, 'store']);
            Route::put('/{productCategory}',    [ProductCategoryController::class, 'update']);
            Route::delete('/{productCategory}', [ProductCategoryController::class, 'destroy']);
        });

        // ===== Member 3 — Product Approval =====
        Route::prefix('products')->group(function () {
            Route::get('/',                   [ProductApprovalController::class, 'index']);
            Route::get('/stats',              [ProductApprovalController::class, 'stats']);
            Route::get('/{product}',          [ProductApprovalController::class, 'show']);
            Route::post('/{product}/approve', [ProductApprovalController::class, 'approve']);
            Route::post('/{product}/reject',  [ProductApprovalController::class, 'reject']);
        });

        // ===== Member 3 — Pet Sale Approval =====
        Route::prefix('pet-sales')->group(function () {
            Route::get('/',                          [PetSaleListingApprovalController::class, 'index']);
            Route::get('/stats',                     [PetSaleListingApprovalController::class, 'stats']);
            Route::get('/{petSaleListing}',          [PetSaleListingApprovalController::class, 'show']);
            Route::post('/{petSaleListing}/approve', [PetSaleListingApprovalController::class, 'approve']);
            Route::post('/{petSaleListing}/reject',  [PetSaleListingApprovalController::class, 'reject']);
        });

        // ===== Member 3 — Chatbot Monitoring =====
        Route::prefix('chatbot')->group(function () {
            Route::get('/conversations',                   [ChatbotMonitoringController::class, 'index']);
            Route::get('/conversations/{conversation}',    [ChatbotMonitoringController::class, 'show']);
            Route::delete('/conversations/{conversation}', [ChatbotMonitoringController::class, 'destroy']);
            Route::get('/stats',                           [ChatbotMonitoringController::class, 'stats']);
        });
    });
});

/*
|--------------------------------------------------------------------------
| Member 2 sub-route files (Lost Pets, Found Pets, Smart Matching, Maps)
|--------------------------------------------------------------------------
*/
require __DIR__.'/lost_pets.php';
require __DIR__.'/found_pets.php';
require __DIR__.'/smart_matching.php';
require __DIR__.'/maps.php';