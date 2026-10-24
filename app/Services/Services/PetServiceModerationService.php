<?php

namespace App\Services\Services;

use App\Models\Notification;
use App\Models\PetService;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PetServiceModerationService
{
    public function __construct(
        private NotificationService $notifications,
    ) {}

    public function approve(PetService $service): PetService
    {
        return DB::transaction(function () use ($service) {
            $locked = PetService::query()
                ->lockForUpdate()
                ->findOrFail($service->id);

            // Repeated approval does not send duplicate notifications.
            if ($locked->status === 'approved') {
                return $locked->load('photos');
            }

            $locked->status = 'approved';
            $locked->approved_at = now();
            $locked->rejection_reason = null;
            $locked->removed_at = null;
            $locked->save();

            $this->notifyOwner(
                $locked,
                Notification::TYPE_SERVICE_APPROVED,
                'Service listing approved',
                "Your service listing '{$locked->name}' has been approved.",
            );

            return $locked->load('photos');
        });
    }

    public function reject(
        PetService $service,
        string $reason,
    ): PetService {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'rejection_reason' => [
                    'A rejection reason is required.',
                ],
            ]);
        }

        return DB::transaction(function () use ($service, $reason) {
            $locked = PetService::query()
                ->lockForUpdate()
                ->findOrFail($service->id);

            if (
                $locked->status === 'rejected'
                && $locked->rejection_reason === $reason
            ) {
                return $locked->load('photos');
            }

            $locked->status = 'rejected';
            $locked->rejection_reason = $reason;
            $locked->approved_at = null;
            $locked->removed_at = null;
            $locked->save();

            $this->notifyOwner(
                $locked,
                Notification::TYPE_SERVICE_REJECTED,
                'Service listing rejected',
                "Your service listing '{$locked->name}' was rejected. Reason: {$reason}",
                $reason,
            );

            return $locked->load('photos');
        });
    }

    public function remove(PetService $service): PetService
    {
        return DB::transaction(function () use ($service) {
            $locked = PetService::query()
                ->lockForUpdate()
                ->findOrFail($service->id);

            if ($locked->status === 'removed') {
                return $locked->load('photos');
            }

            $locked->status = 'removed';
            $locked->removed_at = now();
            $locked->approved_at = null;
            $locked->rejection_reason = null;
            $locked->save();

            $this->notifyOwner(
                $locked,
                Notification::TYPE_SERVICE_REMOVED,
                'Service listing removed',
                "Your service listing '{$locked->name}' was removed by an administrator.",
            );

            return $locked->load('photos');
        });
    }

    private function notifyOwner(
        PetService $service,
        string $type,
        string $title,
        string $body,
        ?string $reason = null,
    ): void {
        $this->notifications->send(
            user: $service->user()->firstOrFail(),
            type: $type,
            title: $title,
            body: $body,
            data: [
                'service_id' => (int) $service->id,
                'business_id' => (int) $service->business_id,
                'service_name' => $service->name,
                'status' => $service->status,
                'rejection_reason' => $reason,
            ],
            // Owner can inspect rejected/removed listings here too.
            actionUrl: '/api/services/mine',
        );
    }
}