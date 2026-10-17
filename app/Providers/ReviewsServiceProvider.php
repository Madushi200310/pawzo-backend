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
        $this->loadRoutesFrom(
            base_path('routes/service-bookings.php')
        );

        $this->loadRoutesFrom(
            base_path('routes/marketplace-orders.php')
        );

        $this->loadRoutesFrom(
            base_path('routes/admin-transactions.php')
        );

        $this->loadRoutesFrom(
            base_path('routes/complaints.php')
        );

        $this->loadRoutesFrom(
            base_path('routes/admin-announcements.php')
        );
    }
}