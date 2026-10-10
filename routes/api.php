<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\OtpController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\BusinessTypeController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\PetSaleListingController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\Admin\BusinessVerificationController;
use App\Http\Controllers\Admin\ChatbotMonitoringController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PetSaleListingApprovalController;
use App\Http\Controllers\Admin\ProductApprovalController;
use App\Http\Controllers\Admin\ReviewManagementController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES
|--------------------------------------------------------------------------
*/

// Auth
Route::post('/register', RegisterController::class)->middleware('throttle:10,1');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
Route::post('/email/verify', [OtpController::class, 'verifyEmail'])->middleware('throttle:10,1');
Route::post('/email/resend-otp', [OtpController::class, 'resend'])->middleware('throttle:5,1');

// Business Types (public browse)
Route::get('/business-types', [BusinessTypeController::class, 'index']);
Route::get('/business-types/{businessType}', [BusinessTypeController::class, 'show']);

// Product Categories (public browse)
Route::get('/product-categories', [ProductCategoryController::class, 'index']);
Route::get('/product-categories/{productCategory}', [ProductCategoryController::class, 'show']);

// Products (public browse)
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);

// Pet Sale Listings (public browse)
Route::get('/pet-sales', [PetSaleListingController::class, 'index']);
Route::get('/pet-sales/{petSaleListing}', [PetSaleListingController::class, 'show']);

// Reviews (public list — approved only)
Route::get('/reviews', [ReviewController::class, 'index']);

// AI Chatbot (works for guests and authenticated users)
Route::post('/chatbot/ask', [ChatbotController::class, 'ask'])->middleware('throttle:30,1');
Route::get('/chatbot/conversations', [ChatbotController::class, 'conversations']);
Route::get('/chatbot/conversations/{conversation}', [ChatbotController::class, 'show']);
Route::delete('/chatbot/conversations/{conversation}', [ChatbotController::class, 'destroy']);

/*
|--------------------------------------------------------------------------
| PROTECTED ROUTES (Sanctum)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    // === Auth / User ===
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('/logout', [LoginController::class, 'logout']);

    // === Business Verification (user-side) ===
    Route::get('/businesses', [BusinessController::class, 'index']);
    Route::post('/businesses', [BusinessController::class, 'store']);
    Route::get('/businesses/{business}', [BusinessController::class, 'show']);
    Route::put('/businesses/{business}', [BusinessController::class, 'update']);
    Route::delete('/businesses/{business}', [BusinessController::class, 'destroy']);

    // === Products (seller CRUD) ===
    Route::post('/products', [ProductController::class, 'store']);
    Route::put('/products/{product}', [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);

    // === Pet Sale Listings (seller CRUD) ===
    Route::post('/pet-sales', [PetSaleListingController::class, 'store']);
    Route::put('/pet-sales/{petSaleListing}', [PetSaleListingController::class, 'update']);
    Route::delete('/pet-sales/{petSaleListing}', [PetSaleListingController::class, 'destroy']);

    // === Reviews (authenticated user actions) ===
    Route::post('/reviews', [ReviewController::class, 'store']);
    Route::get('/reviews/mine', [ReviewController::class, 'mine']);
    Route::put('/reviews/{review}', [ReviewController::class, 'update']);
    Route::delete('/reviews/{review}', [ReviewController::class, 'destroy']);

    /*
    |----------------------------------------------------------------------
    | ADMIN ROUTES — protected with 'admin' middleware
    |----------------------------------------------------------------------
    */
    Route::middleware('admin')->prefix('admin')->group(function () {

        // Admin - Dashboard
        Route::get('/dashboard',       [DashboardController::class, 'index']);
        Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

        // Admin - Business Verification
        Route::prefix('business-verifications')->group(function () {
            Route::get('/',            [BusinessVerificationController::class, 'index']);
            Route::get('/stats',       [BusinessVerificationController::class, 'stats']);
            Route::get('/{business}',  [BusinessVerificationController::class, 'show']);
            Route::post('/{business}/approve', [BusinessVerificationController::class, 'approve']);
            Route::post('/{business}/reject',  [BusinessVerificationController::class, 'reject']);
        });

        // Admin - Business Types
        Route::prefix('business-types')->group(function () {
            Route::get('/',                  [BusinessTypeController::class, 'index']);
            Route::post('/',                 [BusinessTypeController::class, 'store']);
            Route::get('/{businessType}',    [BusinessTypeController::class, 'show']);
            Route::put('/{businessType}',    [BusinessTypeController::class, 'update']);
            Route::delete('/{businessType}', [BusinessTypeController::class, 'destroy']);
        });

        // Admin - Product Categories
        Route::prefix('product-categories')->group(function () {
            Route::get('/',                     [ProductCategoryController::class, 'index']);
            Route::post('/',                    [ProductCategoryController::class, 'store']);
            Route::put('/{productCategory}',    [ProductCategoryController::class, 'update']);
            Route::delete('/{productCategory}', [ProductCategoryController::class, 'destroy']);
        });

        // Admin - Product Approval
        Route::prefix('products')->group(function () {
            Route::get('/',                    [ProductApprovalController::class, 'index']);
            Route::get('/stats',               [ProductApprovalController::class, 'stats']);
            Route::get('/{product}',           [ProductApprovalController::class, 'show']);
            Route::post('/{product}/approve',  [ProductApprovalController::class, 'approve']);
            Route::post('/{product}/reject',   [ProductApprovalController::class, 'reject']);
        });

        // Admin - Pet Sale Approval
        Route::prefix('pet-sales')->group(function () {
            Route::get('/',                           [PetSaleListingApprovalController::class, 'index']);
            Route::get('/stats',                      [PetSaleListingApprovalController::class, 'stats']);
            Route::get('/{petSaleListing}',           [PetSaleListingApprovalController::class, 'show']);
            Route::post('/{petSaleListing}/approve',  [PetSaleListingApprovalController::class, 'approve']);
            Route::post('/{petSaleListing}/reject',   [PetSaleListingApprovalController::class, 'reject']);
        });

        // Admin - Chatbot Monitoring
        Route::prefix('chatbot')->group(function () {
            Route::get('/conversations',                   [ChatbotMonitoringController::class, 'index']);
            Route::get('/conversations/{conversation}',    [ChatbotMonitoringController::class, 'show']);
            Route::delete('/conversations/{conversation}', [ChatbotMonitoringController::class, 'destroy']);
            Route::get('/stats',                           [ChatbotMonitoringController::class, 'stats']);
        });

        // Admin - Reviews Management
        Route::prefix('reviews')->group(function () {
            Route::get('/',                    [ReviewManagementController::class, 'index']);
            Route::get('/stats',               [ReviewManagementController::class, 'stats']);
            Route::get('/{review}',            [ReviewManagementController::class, 'show']);
            Route::post('/{review}/approve',   [ReviewManagementController::class, 'approve']);
            Route::post('/{review}/reject',    [ReviewManagementController::class, 'reject']);
            Route::delete('/{review}',         [ReviewManagementController::class, 'destroy']);
        });
    });
});