<?php

use App\Http\Controllers\MarketplaceOrderController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')
    ->middleware(['api', 'auth:sanctum'])
    ->group(function () {
        Route::get(
            '/marketplace-orders/mine',
            [MarketplaceOrderController::class, 'mine'],
        );

        Route::get(
            '/marketplace-orders/incoming',
            [MarketplaceOrderController::class, 'incoming'],
        );

        Route::post(
            '/marketplace-orders',
            [MarketplaceOrderController::class, 'store'],
        );

        Route::patch(
            '/marketplace-orders/{marketplaceOrder}',
            [MarketplaceOrderController::class, 'respond'],
        );
    });