<?php

use App\Http\Controllers\Admin\AdminAuditLogController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/admin')
    ->middleware(['api', 'auth:sanctum', 'admin'])
    ->group(function () {
        Route::get(
            '/audit-logs',
            [AdminAuditLogController::class, 'index'],
        );
    });