<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Reminder;
use App\Services\Notifications\NotificationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ReminderNotificationService
{
    public function __construct(
        private NotificationService $notifications,
    ) {}

    public function sendDue(): int
    {
        $sent = 0;
        $cutoff = now();

        Reminder::query()
            ->whereNull('notified_at')
            ->where('is_completed', false)
            ->where('is_dismissed', false)
            ->where(function (Builder $query) use ($cutoff) {
                $query
                    ->where('remind_at', '<=', $cutoff)
                    ->orWhere(function (Builder $dates) use ($cutoff) {
                        $dates
                            ->whereNull('remind_at')
                            ->whereDate(
                                'due_date',
                                '<=',
                                $cutoff->toDateString()
                            );
                    });
            })
            ->chunkById(100, function ($reminders) use (
                &$sent,
                $cutoff,
            ) {
                foreach ($reminders as $candidate) {
                    $sent += DB::transaction(function () use (
                        $candidate,
                        $cutoff,
                    ) {
                        $reminder = Reminder::query()
                            ->lockForUpdate()
                            ->find($candidate->id);

                        if (
                            ! $reminder
                            || $reminder->notified_at !== null
                            || $reminder->is_completed
                            || $reminder->is_dismissed
                        ) {
                            return 0;
                        }

                        // Recheck timing after acquiring the row lock.
                        $due = $reminder->remind_at !== null
                            ? $reminder->remind_at->lte($cutoff)
                            : $reminder->due_date
                                ->startOfDay()
                                ->lte($cutoff);

                        if (! $due) {
                            return 0;
                        }

                        $owner = $reminder->user;
                        $pet = $reminder->pet;

                        if (
                            ! $owner
                            || ! $owner->is_active
                            || $owner->email_verified_at === null
                            || ! $pet
                            || (int) $pet->user_id !== (int) $owner->id
                        ) {
                            return 0;
                        }

                        $type = match ($reminder->type) {
                            'vaccination' =>
                                Notification::TYPE_VACCINATION_REMINDER,
                            'medication' =>
                                Notification::TYPE_MEDICINE_REMINDER,
                            default =>
                                Notification::TYPE_HEALTH_REMINDER,
                        };

                        $this->notifications->send(
                            user: $owner,
                            type: $type,
                            title: mb_substr($reminder->title, 0, 200),
                            body: $reminder->description
                                ?: "A health reminder for {$pet->name} is due.",
                            data: [
                                'reminder_id' => (int) $reminder->id,
                                'pet_id' => (int) $pet->id,
                                'reminder_type' => $reminder->type,
                                'due_date' => $reminder->due_date
                                    ->toDateString(),
                                'remind_at' => $reminder->remind_at
                                    ?->toISOString(),
                            ],
                            actionUrl: "/api/reminders/{$reminder->id}",
                        );

                        // Save together with the notification.
                        $reminder->notified_at = $cutoff;
                        $reminder->save();

                        return 1;
                    });
                }
            });

        return $sent;
    }
}