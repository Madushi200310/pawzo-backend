<?php

namespace App\Providers;

use App\Models\FoundPetReport;
use App\Models\LostPetReport;
use App\Observers\PetMatchingObserver;
use Illuminate\Support\ServiceProvider;

class SmartMatchingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        LostPetReport::observe(PetMatchingObserver::class);
        FoundPetReport::observe(PetMatchingObserver::class);
    }
}