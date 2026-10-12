<?php

namespace App\Services\Services;

use App\Models\PetService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

class PetServiceModerationService
{
    public function __construct(
        private NotificationService $notifications
    ) {}

    public function approve(PetService $service): PetService
    {
        return DB::transaction(function () use ($service) {
            $locked = PetService::query()->lockForUpdate()->findOrFail($service->id);
            $locked->status = 'approved';
            $locked->approved_at = now();
            $locked->rejection_reason = null;
            $locked->removed_at = null;
            $locked->save();

            $this->notifications->businessApproved(
                owner: $locked->user,
                businessId: $locked->business_id,
                businessName: $locked->name,
            );

            return $locked->load('photos');
        });
    }

    public function reject(PetService $service, string $reason): PetService
    {
        return DB::transaction(function () use ($service, $reason) {
            $locked = PetService::query()->lockForUpdate()->findOrFail($service->id);
            $locked->status = 'rejected';
            $locked->rejection_reason = $reason;
            $locked->approved_at = null;
            $locked->save();

            $this->notifications->businessRejected(
                owner: $locked->user,
                businessId: $locked->business_id,
                businessName: $locked->name,
                reason: $reason,
            );

            return $locked->load('photos');
        });
    }

    public function remove(PetService $service): PetService
    {
        return DB::transaction(function () use ($service) {
            $locked = PetService::query()->lockForUpdate()->findOrFail($service->id);
            $locked->status = 'removed';
            $locked->removed_at = now();
            $locked->save();

            return $locked->load('photos');
        });
    }
}