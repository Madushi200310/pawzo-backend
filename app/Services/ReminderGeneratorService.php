<?php

namespace App\Services;

use App\Models\HealthRecord;
use App\Models\Pet;
use App\Models\Reminder;
use App\Models\Vaccination;

class ReminderGeneratorService
{
    /**
     * Scan all pets and generate missing reminders.
     * Idempotent: safe to run repeatedly (unique index protects).
     *
     * @return array{created:int}
     */
    public function generateForAllPets(): array
    {
        $created = 0;

        Pet::with(['vaccinations', 'healthRecords'])->chunk(100, function ($pets) use (&$created) {
            foreach ($pets as $pet) {
                $created += $this->generateForPet($pet);
            }
        });

        return ['created' => $created];
    }

    /**
     * Generate reminders for a single pet.
     */
    public function generateForPet(Pet $pet): int
    {
        $created = 0;
        $created += $this->generateVaccinationReminders($pet);
        $created += $this->generateMedicationReminders($pet);

        return $created;
    }

    // ---------------- Internal ----------------

    private function generateVaccinationReminders(Pet $pet): int
    {
        $created = 0;

        $pet->vaccinations()
            ->whereNotNull('next_due_date')
            ->where('next_due_date', '>=', now()->startOfDay())
            ->where('next_due_date', '<=', now()->addDays(30)->endOfDay())
            ->get()
            ->each(function (Vaccination $vax) use ($pet, &$created) {
                $exists = Reminder::where('source_type', Vaccination::class)
                    ->where('source_id', $vax->id)
                    ->where('type', 'vaccination')
                    ->exists();

                if ($exists) {
                    return;
                }

                Reminder::create([
                    'pet_id'      => $pet->id,
                    'user_id'     => $pet->user_id,
                    'type'        => 'vaccination',
                    'title'       => "{$vax->vaccine_name} booster due",
                    'description' => "Next dose of {$vax->vaccine_name} is due on {$vax->next_due_date->toDateString()}.",
                    'due_date'    => $vax->next_due_date,
                    'source_type' => Vaccination::class,
                    'source_id'   => $vax->id,
                ]);

                $created++;
            });

        return $created;
    }

    private function generateMedicationReminders(Pet $pet): int
    {
        $created = 0;

        $pet->healthRecords()
            ->where('record_type', 'medication')
            ->where('is_ongoing', true)
            ->whereNotNull('end_date')
            ->where('end_date', '>=', now()->startOfDay())
            ->get()
            ->each(function (HealthRecord $record) use ($pet, &$created) {
                $exists = Reminder::where('source_type', HealthRecord::class)
                    ->where('source_id', $record->id)
                    ->where('type', 'medication')
                    ->exists();

                if ($exists) {
                    return;
                }

                Reminder::create([
                    'pet_id'      => $pet->id,
                    'user_id'     => $pet->user_id,
                    'type'        => 'medication',
                    'title'       => "{$record->medication_name} course ends",
                    'description' => "Medication {$record->medication_name} ({$record->dosage}) ends on {$record->end_date->toDateString()}.",
                    'due_date'    => $record->end_date,
                    'source_type' => HealthRecord::class,
                    'source_id'   => $record->id,
                ]);

                $created++;
            });

        return $created;
    }
}