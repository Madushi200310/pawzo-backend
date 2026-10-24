<?php

use App\Http\Controllers\Admin\AdminTransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/admin')
    ->middleware(['api', 'auth:sanctum', 'admin'])
    ->group(function () {
        foreach ([
            'service-bookings' => 'booking',
            'marketplace-orders' => 'order',
        ] as $path => $kind) {
            Route::get(
                $path,
                [AdminTransactionController::class, 'index'],
            )->defaults('kind', $kind);

            Route::get(
                $path.'/{id}',
                [AdminTransactionController::class, 'show'],
            )->whereNumber('id')
                ->defaults('kind', $kind);

            Route::patch(
                $path.'/{id}/cancel',
                [AdminTransactionController::class, 'cancel'],
            )->whereNumber('id')
                ->defaults('kind', $kind);
        }
    });