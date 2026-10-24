<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Models\HealthRecord;
use App\Models\MedicationSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MedicationScheduleController extends Controller
{
    private function authorizeRecord(
        Request $request,
        HealthRecord $record,
    ): void {
        $user = $request->user();

        abort_unless($user, 401);

        abort_unless(
            $user->is_active
                && $user->email_verified_at !== null
                && $record->pet
                && (int) $record->pet->user_id === (int) $user->id,
            403,
            'You cannot manage this medication schedule.',
        );
    }

    private function ensureMedication(HealthRecord $record): void
    {
        if (
            $record->record_type !== 'medication'
            || ! $record->is_ongoing
            || ! $record->medication_name
            || ! $record->start_date
        ) {
            throw ValidationException::withMessages([
                'health_record' => [
                    'An ongoing medication record with a name and start date is required.',
                ],
            ]);
        }
    }

    public function show(
        Request $request,
        HealthRecord $healthRecord,
    ): JsonResponse {
        $this->authorizeRecord($request, $healthRecord);

        return response()->json([
            'data' => MedicationSchedule::where(
                'health_record_id',
                $healthRecord->id,
            )->first(),
        ]);
    }

    public function store(
        Request $request,
        HealthRecord $healthRecord,
    ): JsonResponse {
        $this->authorizeRecord($request, $healthRecord);

        $data = $request->validate([
            'timezone' => ['required', 'string', 'timezone', 'max:100'],
            'times' => ['required', 'array', 'min:1', 'max:12'],
            'times.*' => ['required', 'date_format:H:i', 'distinct'],
            'health_record_id' => ['prohibited'],
            'next_due_at' => ['prohibited'],
            'last_notified_at' => ['prohibited'],
        ]);

        $schedule = DB::transaction(function () use ($healthRecord, $data) {
            $record = HealthRecord::query()
                ->lockForUpdate()
                ->findOrFail($healthRecord->id);

            $this->ensureMedication($record);

            if (MedicationSchedule::where(
                'health_record_id',
                $record->id,
            )->exists()) {
                throw ValidationException::withMessages([
                    'health_record' => [
                        'A schedule already exists. Delete it before replacing its dose times.',
                    ],
                ]);
            }

            $times = $data['times'];
            sort($times);

            $schedule = new MedicationSchedule([
                'health_record_id' => $record->id,
                'timezone' => $data['timezone'],
                'times' => $times,
                'is_active' => true,
            ]);

            $schedule->setRelation('healthRecord', $record);
            $schedule->next_due_at = $schedule->nextDueAfter(now());

            if ($schedule->next_due_at === null) {
                throw ValidationException::withMessages([
                    'times' => [
                        'No future dose time exists within the medication dates.',
                    ],
                ]);
            }

            $schedule->save();

            return $schedule;
        });

        return response()->json([
            'message' => 'Medication schedule created.',
            'data' => $schedule->fresh(),
        ], 201);
    }

    public function update(
        Request $request,
        HealthRecord $healthRecord,
    ): JsonResponse {
        $this->authorizeRecord($request, $healthRecord);

        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $schedule = DB::transaction(function () use ($healthRecord, $data) {
            $schedule = MedicationSchedule::where(
                'health_record_id',
                $healthRecord->id,
            )->lockForUpdate()->firstOrFail();

            $record = $healthRecord->fresh();
            abort_unless($record, 404);

            $schedule->setRelation('healthRecord', $record);

            if ($data['is_active']) {
                $this->ensureMedication($record);

                $schedule->next_due_at = $schedule->nextDueAfter(now());

                if ($schedule->next_due_at === null) {
                    throw ValidationException::withMessages([
                        'is_active' => [
                            'No future dose time exists within the medication dates.',
                        ],
                    ]);
                }
            } else {
                $schedule->next_due_at = null;
            }

            $schedule->is_active = (bool) $data['is_active'];
            $schedule->save();

            return $schedule;
        });

        return response()->json([
            'message' => 'Medication schedule updated.',
            'data' => $schedule->fresh(),
        ]);
    }

    public function destroy(
        Request $request,
        HealthRecord $healthRecord,
    ): JsonResponse {
        $this->authorizeRecord($request, $healthRecord);

        DB::transaction(function () use ($healthRecord) {
            $schedule = MedicationSchedule::where(
                'health_record_id',
                $healthRecord->id,
            )->lockForUpdate()->first();

            $schedule?->delete();
        });

        return response()->json([
            'message' => 'Medication schedule deleted.',
        ]);
    }
}