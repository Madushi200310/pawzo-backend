<?php

use App\Http\Controllers\Notifications\NotificationController;
use App\Http\Middleware\EnsureLostPetAccess;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', EnsureLostPetAccess::class, 'throttle:120,1,notifications:'])
    ->prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead'])->name('read-all');
        Route::patch('/{notification}/read', [NotificationController::class, 'markAsRead'])
            ->whereNumber('notification')->name('read');
        Route::delete('/{notification}', [NotificationController::class, 'destroy'])
            ->whereNumber('notification')->name('destroy');
    });