<?php

namespace App\Services\Notifications;

use App\Models\Notification;
use App\Models\User;

class NotificationService
{
    /**
     * Send a generic notification.
     */
    public function send(
        User $user,
        string $type,
        string $title,
        string $body,
        array $data = [],
        ?string $actionUrl = null
    ): Notification {
        return Notification::create([
            'user_id' => $user->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data ?: null,
            'action_url' => $actionUrl,
        ]);
    }

    /**
     * Smart match found notification.
     */
    public function smartMatchFound(
        User $user,
        int $reportId,
        string $reportType,
        int $candidateId,
        float $score
    ): Notification {
        return $this->send(
            user: $user,
            type: Notification::TYPE_SMART_MATCH,
            title: 'Possible match found!',
            body: "A possible match has been found for your {$reportType} pet report. Match confidence: {$score}%.",
            data: [
                'report_id' => $reportId,
                'report_type' => $reportType,
                'candidate_id' => $candidateId,
                'score' => $score,
            ],
            actionUrl: "/api/{$reportType}-pets/{$reportId}/matches",
        );
    }

    /**
     * Vaccination due reminder.
     */
    public function vaccinationReminder(
        User $user,
        int $petId,
        string $petName,
        string $vaccineName,
        string $dueDate
    ): Notification {
        return $this->send(
            user: $user,
            type: Notification::TYPE_VACCINATION_REMINDER,
            title: "{$petName}'s vaccination is due soon",
            body: "{$petName}'s {$vaccineName} vaccination is due on {$dueDate}. Please schedule a vet visit.",
            data: [
                'pet_id' => $petId,
                'pet_name' => $petName,
                'vaccine_name' => $vaccineName,
                'due_date' => $dueDate,
            ],
            actionUrl: "/api/pets/{$petId}/vaccinations",
        );
    }

    /**
     * Medicine schedule reminder.
     */
    public function medicineReminder(
        User $user,
        int $petId,
        string $petName,
        string $medicineName,
        string $scheduledTime
    ): Notification {
        return $this->send(
            user: $user,
            type: Notification::TYPE_MEDICINE_REMINDER,
            title: "Time to give {$petName} medicine",
            body: "Give {$petName} their {$medicineName} dose as scheduled at {$scheduledTime}.",
            data: [
                'pet_id' => $petId,
                'pet_name' => $petName,
                'medicine_name' => $medicineName,
                'scheduled_time' => $scheduledTime,
            ],
            actionUrl: "/api/pets/{$petId}/medications",
        );
    }

    /**
     * Rental request notification.
     */
    public function rentalRequest(
        User $owner,
        int $rentalId,
        string $petName,
        int $requesterId,
        string $requesterName,
        string $startDate,
        string $endDate
    ): Notification {
        return $this->send(
            user: $owner,
            type: Notification::TYPE_RENTAL_REQUEST,
            title: "New rental request for {$petName}",
            body: "{$requesterName} wants to rent {$petName} from {$startDate} to {$endDate}.",
            data: [
                'rental_id' => $rentalId,
                'pet_name' => $petName,
                'requester_id' => $requesterId,
                'requester_name' => $requesterName,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            actionUrl: "/api/rentals/{$rentalId}/requests",
        );
    }

    /**
     * Business approved notification.
     */
    public function businessApproved(
        User $owner,
        int $businessId,
        string $businessName
    ): Notification {
        return $this->send(
            user: $owner,
            type: Notification::TYPE_BUSINESS_APPROVED,
            title: 'Business verification approved!',
            body: "Your business '{$businessName}' has been approved. You can now publish listings.",
            data: [
                'business_id' => $businessId,
                'business_name' => $businessName,
            ],
            actionUrl: "/api/businesses/{$businessId}",
        );
    }

    /**
     * Business rejected notification.
     */
    public function businessRejected(
        User $owner,
        int $businessId,
        string $businessName,
        string $reason
    ): Notification {
        return $this->send(
            user: $owner,
            type: Notification::TYPE_BUSINESS_REJECTED,
            title: 'Business verification rejected',
            body: "Your business '{$businessName}' was not approved. Reason: {$reason}",
            data: [
                'business_id' => $businessId,
                'business_name' => $businessName,
                'reason' => $reason,
            ],
            actionUrl: "/api/businesses/{$businessId}",
        );
    }

    /**
     * Mark single notification as read.
     *
     * Returns the same instance after update — do NOT return fresh().
     * Callers pass this return value to the Resource; the Resource
     * reads read_at from the same instance.
     */
    public function markAsRead(Notification $notification): Notification
    {
        if ($notification->read_at === null) {
            $notification->read_at = now();
            $notification->save();
        }

        // refresh() reloads the SAME instance with fresh DB values.
        // (fresh() returns a NEW instance — the caller may hold a stale ref.)
        $notification->refresh();

        return $notification;
    }

    /**
     * Mark all of a user's unread notifications as read.
     */
    public function markAllAsRead(User $user): int
    {
        return Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Get unread count.
     */
    public function unreadCount(User $user): int
    {
        return Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();
    }
}