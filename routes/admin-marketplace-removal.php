<?php

use App\Http\Controllers\Admin\AdminMarketplaceRemovalController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/admin')
    ->middleware(['api', 'auth:sanctum', 'admin'])
    ->group(function () {
        Route::patch(
            '/products/{product}/remove',
            [AdminMarketplaceRemovalController::class, 'product'],
        )->whereNumber('product');

        Route::patch(
            '/pet-sales/{petSaleListing}/remove',
            [AdminMarketplaceRemovalController::class, 'petSale'],
        )->whereNumber('petSaleListing');
    });