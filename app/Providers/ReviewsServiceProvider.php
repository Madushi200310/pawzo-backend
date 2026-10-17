<?php

namespace App\Providers;

use App\Services\Reviews\AlwaysAllowBookingVerifier;
use App\Services\Reviews\BookingVerifier;
use App\Services\Reviews\DatabaseBookingVerifier;
use Illuminate\Support\ServiceProvider;

class ReviewsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BookingVerifier::class, function () {
            if (config('reviews.allow_unverified', false)) {
                return new AlwaysAllowBookingVerifier();
            }

            return new DatabaseBookingVerifier();
        });
    }

    public function boot(): void
    {
        foreach ([
            'service-bookings.php',
            'marketplace-orders.php',
            'admin-transactions.php',
            'complaints.php',
            'admin-announcements.php',
            'admin-audit.php',
            'listing-categories.php',
            'medication-schedules.php',
        ] as $file) {
            $this->loadRoutesFrom(base_path('routes/'.$file));
        }
    }
}