<?php

namespace App\Services;

use App\Models\MedicationSchedule;
use App\Models\Notification;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

class MedicationNotificationService
{
    public function __construct(
        private NotificationService $notifications,
    ) {}

    public function sendDue(): int
    {
        $cutoff = now()->utc();
        $sent = 0;

        MedicationSchedule::query()
            ->where('is_active', true)
            ->whereNotNull('next_due_at')
            ->where('next_due_at', '<=', $cutoff)
            ->chunkById(100, function ($schedules) use (&$sent, $cutoff) {
                foreach ($schedules as $candidate) {
                    $sent += DB::transaction(function () use (
                        $candidate,
                        $cutoff,
                    ) {
                        $schedule = MedicationSchedule::query()
                            ->lockForUpdate()
                            ->find($candidate->id);

                        if (
                            ! $schedule
                            || ! $schedule->is_active
                            || ! $schedule->next_due_at
                            || $schedule->next_due_at->gt($cutoff)
                        ) {
                            return 0;
                        }

                        $record = $schedule->healthRecord;
                        $pet = $record?->pet;
                        $owner = $pet?->user;

                        if (
                            ! $record
                            || ! $pet
                            || ! $owner
                            || $record->record_type !== 'medication'
                            || ! $record->is_ongoing
                            || ! $record->medication_name
                            || ! $record->start_date
                            || ! $owner->is_active
                            || $owner->email_verified_at === null
                        ) {
                            $schedule->update([
                                'is_active' => false,
                                'next_due_at' => null,
                            ]);

                            return 0;
                        }

                        $localToday = $cutoff->copy()
                            ->setTimezone($schedule->timezone)
                            ->toDateString();

                        $start = $record->start_date->toDateString();
                        $end = $record->end_date?->toDateString();

                        if ($end !== null && $localToday > $end) {
                            $schedule->update([
                                'is_active' => false,
                                'next_due_at' => null,
                            ]);

                            return 0;
                        }

                        if ($localToday < $start) {
                            $schedule->update([
                                'next_due_at' => $schedule->nextDueAfter($cutoff),
                            ]);

                            return 0;
                        }

                        $due = $schedule->next_due_at->copy();
                        $localDue = $due->copy()
                            ->setTimezone($schedule->timezone);

                        $this->notifications->send(
                            user: $owner,
                            type: Notification::TYPE_MEDICINE_REMINDER,
                            title: mb_substr(
                                "Medication reminder for {$pet->name}",
                                0,
                                150,
                            ),
                            body: "Scheduled medication: {$record->medication_name}. "
                                ."Dose time: {$localDue->format('Y-m-d H:i')} "
                                ."({$schedule->timezone}).",
                            data: [
                                'medication_schedule_id' => $schedule->id,
                                'health_record_id' => $record->id,
                                'pet_id' => $pet->id,
                                'medicine_name' => $record->medication_name,
                                'dosage' => $record->dosage,
                                'scheduled_at' => $due->toISOString(),
                                'timezone' => $schedule->timezone,
                            ],
                            actionUrl: "/api/pets/{$pet->id}/health-records/{$record->id}",
                        );

                        $next = $schedule->nextDueAfter($cutoff);

                        $schedule->update([
                            'last_notified_at' => $cutoff,
                            'next_due_at' => $next,
                            'is_active' => $next !== null,
                        ]);

                        return 1;
                    });
                }
            });

        return $sent;
    }
}