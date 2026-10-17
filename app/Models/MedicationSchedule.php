<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class MedicationSchedule extends Model
{
    protected $fillable = [
        'health_record_id',
        'timezone',
        'times',
        'is_active',
        'next_due_at',
        'last_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'times' => 'array',
            'is_active' => 'boolean',
            'next_due_at' => 'datetime',
            'last_notified_at' => 'datetime',
        ];
    }

    public function healthRecord(): BelongsTo
    {
        return $this->belongsTo(HealthRecord::class);
    }

    public function nextDueAfter(Carbon $after): ?Carbon
    {
        $record = $this->healthRecord;

        if (! $record || ! $record->start_date) {
            return null;
        }

        $localAfter = $after->copy()->setTimezone($this->timezone);

        // Health record dates are calendar dates, not UTC instants.
        $start = $record->start_date->toDateString();
        $end = $record->end_date?->toDateString();

        $date = max($localAfter->toDateString(), $start);

        $times = $this->times;
        sort($times);

        // Daily schedules need only the candidate day and the next day.
        for ($offset = 0; $offset < 2; $offset++) {
            $day = Carbon::parse($date, $this->timezone)
                ->addDays($offset)
                ->toDateString();

            if ($end !== null && $day > $end) {
                return null;
            }

            foreach ($times as $time) {
                $candidate = Carbon::parse(
                    $day.' '.$time.':00',
                    $this->timezone,
                )->utc();

                if ($candidate->gt($after)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
}