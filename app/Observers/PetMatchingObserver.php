<?php

namespace App\Observers;

use App\Models\FoundPetReport;
use App\Models\LostPetReport;
use App\Services\Matching\SmartMatchingService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PetMatchingObserver implements ShouldHandleEventsAfterCommit
{
    public function saved(
        LostPetReport|FoundPetReport $report
    ): void {
        try {
            // Allows application startup before the migration is applied.
            if (! Schema::hasTable('pet_matches')) {
                return;
            }

            app(SmartMatchingService::class)->refresh(
                $report instanceof LostPetReport ? 'lost' : 'found',
                (int) $report->id
            );
        } catch (Throwable $error) {
            // The original report transaction has already committed.
            // A matching failure must not undo the report or its photos.
            Log::error(
                'Automatic pet matching failed; retry through the refresh endpoint.',
                [
                    'report_type' => $report::class,
                    'report_id' => $report->id,
                    'error' => $error->getMessage(),
                ]
            );
        }
    }
}